<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use PAMI\Client\Impl\ClientImpl;
use PAMI\Client\Exception\ClientException;
use PAMI\Message\Action\PingAction;
use PAMI\Message\Action\CommandAction;

set_error_handler(function ($level, $message, $file, $line) {
    if (error_reporting() & $level) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
    return false;
});
function check($condition, $message) {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function request($socket) {
    $data = '';
    while (($line = fgets($socket)) !== false) {
        $data .= $line;
        if ($line === "\r\n") break;
    }
    check(preg_match('/^ActionID:\s*([^\r\n]+)/mi', $data, $matches), 'Missing ActionID');
    return trim($matches[1]);
}
function reply($socket, $id, $status = 'Success') {
    fwrite($socket, "Response: $status\r\nActionID: $id\r\nMessage: Test\r\n\r\n");
}
function scenario($mode) {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    check($server !== false, 'Cannot listen');
    $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
    $pid = pcntl_fork();
    check($pid >= 0, 'Cannot fork');
    if ($pid === 0) {
        try {
            $socket = stream_socket_accept($server, 5);
            check($socket !== false, 'Accept failed');
            stream_set_timeout($socket, 5);
            fwrite($socket, "Asterisk Call Manager/5.0\r\n");
            $id = request($socket);
            reply($socket, $id, $mode === 'login-error' ? 'Error' : 'Success');
            if ($mode !== 'login-error') {
                $id = request($socket);
                if ($mode === 'stress') {
                    for ($i = 0; $i < 10000; $i++) {
                        if ($i > 0) $id = request($socket);
                        reply($socket, $id);
                    }
                } elseif ($mode === 'timeout') {
                    usleep(700000);
                    @fwrite($socket, "Response: Success\r\nMessage: Late\r\n\r\n");
                } elseif ($mode === 'disconnect') {
                    // Close without responding.
                } elseif ($mode === 'fragment') {
                    fwrite($socket, "Response: Success\r\nAction");
                    usleep(50000);
                    fwrite($socket, "ID: $id\r\nMessage: Test\r\n\r\n");
                } elseif ($mode === 'command' || $mode === 'command-error') {
                    fwrite($socket, "Event: FullyBooted\r\nStatus: Fully Booted\r\n\r\n");
                    if ($mode === 'command-error') {
                        reply($socket, $id, 'Error');
                    } else {
                        fwrite($socket, "Response: Success\r\nActionID: $id\r\nOutput: first\r\nOutput: second\r\n\r\n");
                    }
                } else {
                    reply($socket, $id);
                }
            }
            fclose($socket);
            fclose($server);
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . PHP_EOL);
            exit(1);
        }
    }
    fclose($server);
    $client = new ClientImpl(['host'=>'127.0.0.1','port'=>$port,'username'=>'test','secret'=>'test','connect_timeout'=>1000,'read_timeout'=>300]);
    $events = 0;
    $client->registerEventListener(function ($event) use (&$events) { $events++; });
    try {
        $failed = false;
        try {
            $client->open();
            if ($mode === 'login-error') throw new RuntimeException('Rejected login accepted');
            $start = hrtime(true);
            $client->process();
            check((hrtime(true)-$start)/1e6 < 100, 'Idle process blocked');
            $start = hrtime(true);
            try {
                if ($mode === 'stress') {
                    $baseline = memory_get_usage();
                    for ($i = 0; $i < 10000; $i++) {
                        check($client->send(new PingAction())->isSuccess(), 'Stress response failed');
                        if ($i === 99) $baseline = memory_get_usage();
                    }
                    $growth = memory_get_usage() - $baseline;
                    check($growth < 1048576, 'Memory growth exceeds 1 MiB');
                    echo "Stress: 10000 commands, memory growth $growth bytes\n";
                } else {
                $command = in_array($mode, ['command','command-error','timeout'], true);
                $response = $client->send($command ? new CommandAction('core show version') : new PingAction());
                check($response->isSuccess() === ($mode !== 'command-error'), 'Unexpected response status');
                if ($mode === 'command') {
                    check($response->getCommandOutputArray() === ['first','second'], 'Incorrect command output');
                }
                if ($mode === 'command' || $mode === 'command-error') {
                    check($events === 1, 'Event not dispatched during send');
                }
                }
            } finally {
                $elapsed = (hrtime(true)-$start)/1e6;
            }
        } catch (ClientException $e) {
            $failed = true;
        }
        check($failed === in_array($mode, ['login-error','timeout','disconnect'], true), 'Unexpected outcome: '.$mode);
        if ($mode === 'timeout') {
            check(isset($elapsed) && $elapsed >= 250 && $elapsed < 650, 'Incorrect timeout');
            check(!is_resource($client->getSocket()), 'Timed-out connection retained');
        }
    } finally {
        $client->close();
        pcntl_waitpid($pid, $status);
    }
    check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Fake server failed');
    echo "PASS $mode\n";
}
foreach (['login-error','success','fragment','command','command-error','timeout','disconnect','stress'] as $mode) {
    scenario($mode);
}
