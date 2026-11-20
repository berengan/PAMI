<?php
/**
 * TCP Client implementation for AMI.
 *
 * PHP Version 5
 *
 * @category   Pami
 * @package    Client
 * @subpackage Impl
 * @author     Marcelo Gornstein <marcelog@gmail.com>
 * @license    http://marcelog.github.com/PAMI/ Apache License 2.0
 * @version    SVN: $Id$
 * @link       http://marcelog.github.com/PAMI/
 *
 * Copyright 2011 Marcelo Gornstein <marcelog@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */
declare(ticks=1);
namespace PAMI\Client\Impl;

use PAMI\Message\OutgoingMessage;
use PAMI\Message\Message;
use PAMI\Message\IncomingMessage;
use PAMI\Message\Action\LoginAction;
use PAMI\Message\Response\Response;
use PAMI\Message\Event\Factory\Impl\EventFactoryImpl;
use PAMI\Message\Response\Factory\Impl\ResponseFactoryImpl;
use PAMI\Listener\IEventListener;
use PAMI\Client\Exception\ClientException;
use PAMI\Client\IClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * TCP Client implementation for AMI.
 *
 * PHP Version 5
 *
 * @category   Pami
 * @package    Client
 * @subpackage Impl
 * @author     Marcelo Gornstein <marcelog@gmail.com>
 * @license    http://marcelog.github.com/PAMI/ Apache License 2.0
 * @link       http://marcelog.github.com/PAMI/
 */
class ClientImpl implements IClient
{
    /**
     * PSR-3 logger.
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Hostname
     * @var string
     */
    private $host;

    /**
     * TCP Port.
     * @var integer
     */
    private $port;

    /**
     * Username
     * @var string
     */
    private $user;

    /**
     * Password
     * @var string
     */
    private $pass;

    /**
     * Connection timeout, in milliseconds.
     * @var integer
     */
    private $cTimeout;

    /**
     * Connection scheme, like tcp:// or tls://
     * @var string
     */
    private $scheme;

    /**
     * Event factory.
     * @var EventFactoryImpl
     */
    private $eventFactory;

    /**
     * Response factory.
     * @var ResponseFactoryImpl
     */
    private $responseFactory;

    /**
     * R/W timeout, in milliseconds.
     * @var integer
     */
    private $rTimeout;

    /**
     * Our stream socket resource.
     * @var resource
     */
    private $socket;

    /**
     * Our stream context resource.
     * @var resource
     */
    private $context;

    /**
     * Our event listeners
     * @var IEventListener[]
     */
    private $eventListeners;

    /**
     * The receiving queue.
     * @var Response[]
     */
    private $incomingQueue;

    /**
     * Our current received message. May be incomplete, will be completed
     * eventually with an EOM.
     * @var string
     */
    private $currentProcessingMessage;

    /**
     * This should not happen. Asterisk may send responses without a
     * corresponding ActionId.
     * @var string
     */
    private $lastActionId;

    /**
     * Event mask to apply on login action.
     * @var string|null
     */
    private $eventMask;

    /**
     * @object class
     */
    private $lastActionClass;


    /**
     * Return a formatted string containing scheme, host and port
     *
     * @return string
     */
    private function getSocketUri()
    {
        return sprintf('%s%s:%s', $this->scheme, $this->host, $this->port);
    }

    /**
     * Connects a tcp connection to ami.
     *
     * @throws \PAMI\Client\Exception\ClientException
     * @return void
     */
    protected function connect()
    {
        $errno = $errstr = null;
        $socketUri = $this->getSocketUri();
        $this->context = stream_context_create();
        $this->socket = @stream_socket_client(
            $socketUri,
            $errno,
            $errstr,
            $this->cTimeout / 1000,
            STREAM_CLIENT_CONNECT,
            $this->context
        );
        if ($this->socket === false) {
            throw new ClientException(sprintf('error: "%s" while create socket "%s"', $errstr, $socketUri));
        }
        if (!stream_set_blocking($this->socket, false)) {
            $this->close();
            throw new ClientException('Could not set non-blocking AMI socket');
        }
    }

    /**
     * Opens a tcp connection to ami.
     *
     * @throws \PAMI\Client\Exception\ClientException
     * @return void
     */
    public function open()
    {
        $this->close();
        try {
            $this->connect();
            $deadline = $this->deadline($this->rTimeout);
            $banner = '';
            while (strpos($banner, Message::EOL) === false) {
                $this->waitForSocket(false, $deadline);
                $chunk = @fread($this->socket, 8192);
                if ($chunk === false || ($chunk === '' && feof($this->socket))) {
                    throw new ClientException('Error reading AMI greeting');
                }
                $banner .= $chunk;
                if (strlen($banner) > 65536) {
                    throw new ClientException('AMI greeting too large');
                }
            }
            list($asteriskId, $this->currentProcessingMessage) = explode(Message::EOL, $banner, 2);
            if (strpos($asteriskId, 'Asterisk') === false) {
                throw new ClientException('Unknown AMI peer');
            }
            $msg = new LoginAction($this->user, $this->pass);
            $response = $this->send($msg);
            if (!$response->isSuccess()) {
                throw new ClientException('AMI login failed: ' . $response->getMessage());
            }
            $this->logger->info(sprintf('Login to: "%s" by user: "%s"', $this->getSocketUri(), $this->user));
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }
    }

    private function deadline($milliseconds)
    {
        return hrtime(true) + $milliseconds * 1000000;
    }

    private function waitForSocket($writing, $deadline)
    {
        $remaining = $deadline - hrtime(true);
        if ($remaining <= 0) {
            throw new ClientException('AMI timeout');
        }
        $microseconds = (int) ceil($remaining / 1000);
        $read = $writing ? array() : array($this->socket);
        $write = $writing ? array($this->socket) : array();
        $except = null;
        $ready = @stream_select($read, $write, $except, intdiv($microseconds, 1000000), $microseconds % 1000000);
        if ($ready === false) {
            throw new ClientException('AMI socket select failed');
        }
        if ($ready === 0) {
            throw new ClientException('AMI timeout');
        }
    }

    /**
     * Registers the given listener so it can receive events. Returns the generated
     * id for this new listener. You can pass in a an IEventListener, a Closure,
     * and an array containing the object and name of the method to invoke. Can specify
     * an optional predicate to invoke before calling the callback.
     *
     * @param mixed $listener
     * @param \Closure|null $predicate
     *
     * @return string
     */
    public function registerEventListener($listener, $predicate = null)
    {
        $listenerId = uniqid('PamiListener');
        $this->eventListeners[$listenerId] = array($listener, $predicate);
        return $listenerId;
    }

    /**
     * Unregisters an event listener.
     *
     * @param string $listenerId The id returned by registerEventListener.
     *
     * @return void
     */
    public function unregisterEventListener($listenerId)
    {
        if (isset($this->eventListeners[$listenerId])) {
            unset($this->eventListeners[$listenerId]);
        }
    }

    /**
     * Reads a complete message over the stream until EOM.
     *
     * @throws ClientException
     * @return \string[]
     */
    protected function getMessages()
    {
        $msgs = array();
        // Read something.
        //$read = @fread($this->socket, 65535);
        if (!is_resource($this->socket)) {
            throw new ClientException('AMI socket is not connected');
        }
        $read = @fread($this->socket, 8192);
        if ($read === false || (empty($read) && @feof($this->socket))) {
            throw new ClientException('Error reading');
        }
        $this->currentProcessingMessage .= $read;
        // If we have a complete message, then return it. Save the rest for
        // later.
        while (($marker = strpos($this->currentProcessingMessage, Message::EOM)) !== false) {
            $msg = substr($this->currentProcessingMessage, 0, $marker);
            $this->currentProcessingMessage = substr(
                $this->currentProcessingMessage,
                $marker + strlen(Message::EOM)
            );
            if ($msg !== '') {
                $msgs[] = $msg;
            }
        }
        return $msgs;
    }

    /**
     * Main processing loop. Also called from send(), you should call this in
     * your own application in order to continue reading events and responses
     * from ami.
     */
    public function process()
    {
        //$msgs = $this->getMessages($outgoingMessageClass=false);
        $msgs = $this->getMessages();
        foreach ($msgs as $aMsg) {
            $this->logger->debug(
                '------ Received: ------ ' . "\n" . $aMsg . "\n\n"
            );
            $resPos = strpos($aMsg, 'Response:');
            $evePos = strpos($aMsg, 'Event:');
            if (($resPos !== false) &&
              (($resPos < $evePos) || $evePos === false)
            ) {
                $response = $this->messageToResponse($aMsg);
                if ($this->lastActionId !== false && $response->getActionId() === $this->lastActionId) {
                    $this->incomingQueue[$response->getActionId()] = $response;
                }
            } elseif ($evePos !== false) {
                $event = $this->messageToEvent($aMsg);
                $response = $this->findResponse($event);
                if (gettype($response) == "boolean" && $response === false) {
                    $this->dispatch($event);
                } elseif (!($response instanceof Response)) {
                    $this->dispatch($event);
                } elseif ($response->isComplete()) {
                    $this->dispatch($event);
                } else {
                    $response->addEvent($event);
                }
            } else {
                // broken ami.. sending a response with events without
                // Event and ActionId
                $bMsg = 'Event: ResponseEvent' . "\r\n";
                $bMsg .= 'ActionId: ' . $this->lastActionId . "\r\n" . $aMsg;
                $event = $this->messageToEvent($bMsg);
                $response = $this->findResponse($event);
                if ($response instanceof Response) {
                    $response->addEvent($event);
                }
            }
            $this->logger->debug('----------------');
        }
    }


    /**
     * Tries to find an associated response for the given message.
     *
     * @param IncomingMessage $message Message sent by asterisk.
     *
     * @return \PAMI\Message\Response\Response
     */
    protected function findResponse(IncomingMessage $message)
    {
        $actionId = $message->getActionId();
        if ($actionId !== null && isset($this->incomingQueue[$actionId])) {
            return $this->incomingQueue[$actionId];
        }
        return false;
    }

    /**
     * Dispatchs the incoming message to a handler.
     *
     * @param \PAMI\Message\IncomingMessage $message Message to dispatch.
     *
     * @return void
     */
    protected function dispatch(IncomingMessage $message)
    {
        foreach ($this->eventListeners as $data) {
            $listener = $data[0];
            $predicate = $data[1];

            if (!$this->evaluatePredicate($predicate, $message)) {
                continue;
            }

            if ($listener instanceof \Closure) {
                $listener($message);
            } elseif (is_array($listener)) {
                $listener[0]->{$listener[1]}($message);
            } else {
                $listener->handle($message);
            }
        }
    }

    /**
     * Evaluate a predicate for a message.
     *
     * @param $predicate a closure or an array of filters.
     * @param \PAMI\Message\IncomingMessage $message Message to compare.
     *
     * @return bool
     */
    protected function evaluatePredicate($predicate, IncomingMessage $message)
    {
        if (is_callable($predicate)) {
            return call_user_func($predicate, $message);
        }

        if (!is_array($predicate)) {
            return true;
        }

        foreach ($predicate as $key => $value) {
            if (!preg_match($value, $message->getKey($key))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns a Response from a raw string that came from asterisk.
     *
     * @param string $msg Raw string.
     *
     * @return \PAMI\Message\Response\Response
     */
    private function messageToResponse($msg)
    {
        $response = $this->responseFactory->createFromRaw($msg, $this->lastActionClass);
        $actionId = $response->getActionId();
        if (is_null($actionId)) {
            $actionId = $this->lastActionId;
            $response->setActionId($this->lastActionId);
        }
        return $response;
    }

    /**
     * Returns a EventMessage from a raw string that came from asterisk.
     *
     * @param string $msg Raw string.
     *
     * @return \PAMI\Message\Event\EventMessage
     */
    private function messageToEvent($msg)
    {
        return $this->eventFactory->createFromRaw($msg);
    }

    /**
     * Returns a message (response) related to the given message. This uses
     * the ActionID tag (key).
     *
     * @todo not suitable for multithreaded applications.
     *
     * @param OutgoingMessage $message
     *
     * @return \PAMI\Message\Response\Response
     */
    protected function getRelated(OutgoingMessage $message)
    {
        $ret = false;
        $id = $message->getActionID();
        if (isset($this->incomingQueue[$id])) {
            $response = $this->incomingQueue[$id];
            if ($response->isComplete()) {
                unset($this->incomingQueue[$id]);
                $ret = $response;
            }
        }
        return $ret;
    }

    /**
     * Sends a message to ami.
     *
     * @param \PAMI\Message\OutgoingMessage $message Message to send.
     *
     * @see ClientImpl::send()
     * @throws \PAMI\Client\Exception\ClientException
     * @return \PAMI\Message\Response\Response
     */
    public function send(OutgoingMessage $message)
    {
        if (!is_resource($this->socket)) {
            throw new ClientException('AMI socket is not connected');
        }
        if ($this->lastActionId !== false) {
            throw new ClientException('Nested AMI send is not supported');
        }
        if ($message->getActionId() === null) {
            $message->setActionId(bin2hex(random_bytes(8)));
        }
        $data = $message->serialize();
        $length = strlen($data);
        $offset = 0;
        $this->lastActionId = $message->getActionId();
        $this->lastActionClass = $message;
        $deadline = $this->deadline($this->rTimeout);
        try {
            while ($offset < $length) {
                $this->waitForSocket(true, $deadline);
                $written = @fwrite($this->socket, substr($data, $offset));
                if ($written === false || ($written === 0 && feof($this->socket))) {
                    throw new ClientException('Could not send AMI message');
                }
                $offset += $written;
            }
            while (hrtime(true) < $deadline) {
                // Read first, including any bytes already buffered by PHP.
                $this->process();
                if (hrtime(true) >= $deadline) {
                    break;
                }
                $response = $this->getRelated($message);
                if ($response !== false) {
                    return $response;
                }
                $meta = stream_get_meta_data($this->socket);
                if (empty($meta['unread_bytes'])) {
                    $this->waitForSocket(false, $deadline);
                }
            }
            throw new ClientException('AMI read timeout');
        } catch (\Throwable $e) {
            // A late response without ActionID cannot be safely correlated.
            $this->close();
            throw $e;
        } finally {
            $this->lastActionId = false;
            $this->lastActionClass = null;
            $this->incomingQueue = array();
        }
    }

    /**
     * Closes the connection to ami.
     *
     * @return void
     */
    public function close()
    {
        $this->logger->debug('Closing connection to asterisk.');
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->currentProcessingMessage = '';
        $this->incomingQueue = array();
        $this->lastActionId = false;
        $this->lastActionClass = null;
    }

    /**
     * Sets the logger implementation.
     *
     * @param LoggerInterface $logger The PSR3-Logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get the logger implementation.
     *
     * @return @object The current PSR3-Logger instance
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Returns stream socket.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Constructor.
     *
     * @param string[] $options Options for ami client.
     *
     */
    public function __construct(array $options)
    {
        $this->logger = new NullLogger;
        $this->host = $options['host'];
        $this->port = (int) $options['port'];
        $this->user = $options['username'];
        $this->pass = $options['secret'];
        $this->cTimeout = filter_var($options['connect_timeout'] ?? 1000, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        $this->rTimeout = filter_var($options['read_timeout'] ?? 1000, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        if ($this->cTimeout === false || $this->rTimeout === false) {
            throw new ClientException('AMI timeouts must be positive integer milliseconds');
        }
        $this->currentProcessingMessage = '';
        $this->scheme = isset($options['scheme']) ? $options['scheme'] : 'tcp://';
        $this->eventMask = isset($options['event_mask']) ? $options['event_mask'] : null;
        $this->eventListeners = array();
        $this->eventFactory = new EventFactoryImpl();
        $this->responseFactory = new ResponseFactoryImpl();
        $this->incomingQueue = array();
        $this->lastActionId = false;
    }
}
