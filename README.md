# PAMI — Calypso fork

[![Calypso AMI tests](https://github.com/berengan/PAMI/actions/workflows/calypso-tests.yml/badge.svg?branch=calypso-devel)](https://github.com/berengan/PAMI/actions/workflows/calypso-tests.yml)
[![License](https://img.shields.io/badge/license-Apache%202.0-blue.svg)](https://www.apache.org/licenses/LICENSE-2.0)

PHP Asterisk Manager Interface (PAMI) is an object-oriented AMI client providing
synchronous actions and event listeners for Asterisk applications.

This fork is maintained for Calypso, with particular attention to long-running
PHP daemons, predictable socket I/O timeouts and modern PHP compatibility.
It is based on [chan-sccp/PAMI](https://github.com/chan-sccp/PAMI) v2.0.10,
itself derived from [Marcelo Gornstein's original PAMI](https://github.com/marcelog/PAMI).

## Development status

The `calypso-devel` branch is under active development. The planned release is
v2.1.0; it has not yet been published. Do not deploy this branch to production
without validating the application and its Asterisk version.

## Changes from upstream v2.0.10

- Connection and read/write timeouts expressed in milliseconds.
- Non-blocking socket I/O and monotonic deadlines using `hrtime()`.
- Synchronous `send()`, including partial TCP writes and response correlation.
- Non-blocking `process()` for polling incoming AMI messages.
- Explicit login response validation and socket resource cleanup.
- Connection invalidation after failed in-flight requests to prevent ambiguous
  late responses from being associated with subsequent commands.
- Modern header-framed command success/error responses and repeated Output fields.
- Corrections for PHP deprecations, including dynamic properties, non-canonical
  casts and nullable string arguments.
- Automated transport regression tests and memory-growth checks.

## Compatibility

The development target is PHP 7.4 or newer, with CI covering PHP 7.4, 8.4 and
8.5 using PSR-3 log version 1.1.4. The initial transport suite passed on all
three versions; the expanded strict-diagnostics and stress suite also passed on all three versions (10,000 commands per version, zero measured PHP memory growth). Other PHP and PSR-3 versions are not certified.

The Composer package name remains `chan-sccp/pami` and the namespace remains
`PAMI`. No new runtime package dependencies are introduced. Composer requires PHP 7.4 or newer.

## Installation — development only

Add the fork as a VCS repository in your application's composer.json:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/berengan/PAMI.git"
    }
  ],
  "require": {
    "chan-sccp/pami": "dev-calypso-devel"
  }
}
```

Then update only the target package and review the lockfile:

```sh
composer update chan-sccp/pami
```

Keep existing application dependencies and commit the reviewed lockfile.
Use an explicit stable version once a validated release is available.

## Migration from v2.0.10

**Breaking configuration change:** both timeout options now use positive
integer milliseconds rather than seconds. Both default to 1000 ms.

| Option | Old value (seconds) | Equivalent new value (milliseconds) |
| --- | ---: | ---: |
| connect_timeout | 3 | 3000 |
| read_timeout | 2 | 2000 |

Do not multiply values that were already intended to represent milliseconds.

The connection timeout applies to socket connection establishment. The greeting
and login each have a separate read timeout budget. The send deadline covers
writing the request and waiting for its complete response. Application event
callbacks and other synchronous PHP work can extend actual elapsed time.

A failed in-flight send closes the connection and rethrows the exception.
The caller must explicitly reconnect before sending another action.
There is no automatic reconnection or command retry: a timed-out action may
already have been executed by Asterisk. A valid AMI Error response is returned
as a response object rather than treated as a transport timeout.

Nested calls to send() during a pending action are not supported. Event
listeners should enqueue work for later execution instead. Polling loops
should include suitable pacing to avoid busy-spinning on an idle socket.

## Quick start

```php
require __DIR__ . '/vendor/autoload.php';

use PAMI\Client\Impl\ClientImpl;
use PAMI\Client\Exception\ClientException;
use PAMI\Message\Action\CommandAction;

$client = new ClientImpl([
    'host' => '127.0.0.1',
    'scheme' => 'tcp://',
    'port' => 5038,
    'username' => 'ami-user',
    'secret' => getenv('AMI_SECRET'),
    'connect_timeout' => 3000,
    'read_timeout' => 2000,
]);

$client->registerEventListener(function ($event) {
    // Keep callbacks short; queue lengthy work for the main loop.
});

try {
    $client->open();
    $response = $client->send(new CommandAction('core show version'));
    if ($response->isSuccess()) {
        echo $response->getCommandOutput();
    } else {
        error_log((string) $response->getMessage());
    }
    $client->process();
} catch (ClientException $e) {
    error_log($e->getMessage());
    // A daemon should schedule an explicit reconnect, not retry the action blindly.
} finally {
    $client->close();
}
```

## Automated tests

See [GitHub Actions](https://github.com/berengan/PAMI/actions/workflows/calypso-tests.yml)
for current results. The workflow installs isolated runtime dependencies and
lints the PHP source. The Linux transport tests require the pcntl extension.

With Composer runtime dependencies installed:

```sh
php -d error_reporting=32767 tests/Calypso/transport.php
```

The simulated AMI suite covers rejected login, immediate and fragmented
responses, command output, command errors, interleaved events, idle polling,
timeouts, late responses and disconnects. The stress scenario sends 10,000
commands on one connection and checks memory growth after warm-up against a
1 MiB threshold. Unsuppressed PHP diagnostics fail the suite. A Linux-only connection cycle test checks 100 open/send/close cycles, peer EOF and file descriptor growth.

Simulation is not a substitute for real Asterisk integration testing.
Legacy command framing, TLS and the full upstream action/event catalog still
require broader validation.

## Resources

- [Upstream API documentation](doc/api.md)
- [Upstream examples](doc/examples/)
- [Upstream HTML API](https://chan-sccp.github.io/PAMI)
- [Calypso fork issues](https://github.com/berengan/PAMI/issues)

The following action and event catalogs are inherited from upstream; inclusion
does not imply individual regression testing in the Calypso fork.

# Currently Supported Events

More events will be added with time. I can only add the ones I can test for and
use, so your contributions may make the difference! ;)

Unknown (not yet implemented) events will be reported as UnknownEvent, so you
can still catch them. If you catch one of these, please report it!

 * AgentCalled
 * AgentComplete
 * AgentConnect
 * AgentDump
 * Agentlogin
 * Agentlogoff
 * AgentRingNoAnswer
 * Agents
 * AGIExec
 * AGIExecEnd
 * AGIExecStart
 * Alarm
 * AlarmClear
 * AorDetail
 * AorList
 * AorListComplete
 * AsyncAGI
 * AsyncAGIEnd
 * AsyncAGIExec
 * AsyncAGIStart
 * AttendedTransfer
 * AuthDetail
 * AuthList
 * AuthListComplete
 * AuthMethodNotAllowed
 * BlindTransfer
 * Bridge
 * BridgeCreate
 * BridgeDestroy
 * BridgeEnter
 * BridgeInfoChannel
 * BridgeLeave
 * BridgeListItem
 * BridgeVideoSourceUpdate
 * CallAnswered
 * CallForward
 * Cdr
 * CEL
 * ChallengeResponseFailed
 * ChallengeSent
 * ChannelTalkingStart
 * ChannelTalkingStop
 * ChannelUpdate
 * ChanSpyStart
 * ChanSpyStop
 * ConfbridgeEnd
 * ConfbridgeJoin
 * ConfbridgeLeave
 * ConfbridgeList
 * ConfbridgeListRooms
 * ConfbridgeMute
 * ConfbridgeRecord
 * ConfbridgeStart
 * ConfbridgeStopRecord
 * ConfbridgeTalking
 * ConfbridgeUnmute
 * ContactList
 * ContactListComplete
 * ContactStatus
 * ContactStatusDetail
 * CoreShowChannel
 * DAHDIChannel
 * DAHDIShowChannels
 * DBGetResponse
 * DeviceStateChange
 * DeviceStateListComplete
 * DeviceStatus
 * Dial
 * DialBegin
 * DialEnd
 * DialState
 * DND
 * DNDState
 * DongleNewCUSD
 * DongleNewUSSD
 * DongleNewUSSDBase64
 * DongleSMSStatus
 * DongleStatus
 * DongleUSSDStatus
 * DTMF
 * DTMFBegin
 * DTMFEnd
 * EndpointDetail
 * EndpointDetailComplete
 * EndpointList
 * EventFactoryImpl.php
 * EventMessage.php
 * ExtensionStateListComplete
 * ExtensionStatus
 * Factory
 * FailedACL
 * FAXSession
 * FAXSessionsComplete
 * FAXSessionsEntry
 * FAXStats
 * FAXStatus
 * FullyBooted
 * Hangup
 * HangupHandlerPop
 * HangupHandlerPush
 * HangupHandlerRun
 * HangupRequest
 * Hold
 * IdentifyDetail
 * Impl
 * InboundRegistrationDetail
 * InvalidAccountID
 * InvalidPassword
 * InvalidTransport
 * JabberEvent
 * Join
 * Leave
 * Link
 * ListDialplan
 * Load
 * LoadAverageLimit
 * LocalBridge
 * LocalOptimizationBegin
 * LocalOptimizationEnd
 * Masquerade
 * MCID
 * MeetmeEnd
 * MeetmeJoin
 * MeetmeLeave
 * MeetmeMute
 * MeetmeTalking
 * MeetmeTalkRequest
 * MemoryLimit
 * MessageWaiting
 * MiniVoiceMail
 * MonitorStart
 * MonitorStop
 * MusicOnHold
 * MusicOnHoldStart
 * MusicOnHoldStop
 * MWIGet
 * MWIGetComplete
 * NewAccountCode
 * NewCallerid
 * Newchannel
 * NewConnectedLine
 * Newexten
 * Newstate
 * OriginateResponse
 * OutboundRegistrationDetail
 * OutboundSubscriptionDetail
 * ParkedCall
 * ParkedCallGiveUp
 * ParkedCallSwap
 * ParkedCallTimeOut
 * PeerStatus
 * Pickup
 * PresenceStateChange
 * PresenceStateListComplete
 * PresenceStatus
 * QueueCallerAbandon
 * QueueCallerJoin
 * QueueCallerLeave
 * QueueMember
 * QueueMemberAdded
 * QueueMemberPause
 * QueueMemberPaused
 * QueueMemberPenalty
 * QueueMemberRemoved
 * QueueMemberRinginuse
 * QueueMemberStatus
 * QueueParams
 * QueueSummary
 * ReceiveFAX
 * Registry
 * Reload
 * Rename
 * RequestBadFormat
 * RequestNotAllowed
 * RequestNotSupported
 * ResourceListDetail
 * RTCPReceived
 * RTCPReceiverStat
 * RTCPSent
 * RTPReceiverStat
 * RTPSenderStat
 * SCCPConfEnd
 * SCCPConfEntered
 * SCCPConfLeave
 * SCCPConfLeft
 * SCCPConfLock
 * SCCPConfParticipantKicked
 * SCCPConfParticipantMute
 * SCCPConfParticipantPromotion
 * SCCPConfStart
 * SCCPConfStarted
 * SCCPShowDevice
 * SCCPShowLine
 * SendFAX
 * SessionLimit
 * SessionTimeout
 * Shutdown
 * SIPQualifyPeerDone
 * SoftHangupRequest
 * SpanAlarm
 * SpanAlarmClear
 * Status
 * Success
 * SuccessfulAuth
 * TableEnd
 * TableStart
 * Transfer
 * TransportDetail
 * UnexpectedAddress
 * Unhold
 * Unknown
 * Unlink
 * Unload
 * UnParkedCall
 * UserEvent
 * VarSet
 * VgsmMeState
 * VgsmNetState
 * VgsmSmsRx

# Currently Supported Actions

 * AbsoluteTimeout
 * Action
 * ActionMessage.php
 * AgentLogoff
 * Agents
 * AGI
 * AttendedTransfer
 * Atxfer
 * BlindTransfer
 * Bridge
 * BridgeDestroy
 * BridgeInfo
 * BridgeKick
 * BridgeList
 * BridgeTechnologyList
 * BridgeTechnologySuspend
 * BridgeTechnologyUnsuspend
 * CancelAtxfer
 * Challenge
 * ChangeMonitor
 * Command
 * ConfbridgeKick
 * ConfbridgeList
 * ConfbridgeListRooms
 * ConfbridgeLock
 * ConfbridgeMute
 * ConfbridgeSetSingleVideoSrc
 * ConfbridgeStartRecord
 * ConfbridgeStopRecord
 * ConfbridgeUnlock
 * ConfbridgeUnmute
 * ControlPlayback
 * CoreSettings
 * CoreShowChannels
 * CoreStatus
 * CreateConfig
 * DAHDIDialOffHook
 * DAHDIDNDOff
 * DAHDIDNDOn
 * DAHDIHangup
 * DAHDIRestart
 * DAHDIShowChannels
 * DAHDITransfer
 * DBDel
 * DBDelTree
 * DBGet
 * DBPut
 * DeviceStateList
 * DialplanExtensionAdd
 * DialplanExtensionRemove
 * DongleReload
 * DongleReset
 * DongleRestart
 * DongleSendPDU
 * DongleSendSMS
 * DongleSendUSSD
 * DongleShowDevices
 * DongleStart
 * DongleStop
 * Events
 * ExtensionState
 * ExtensionStateList
 * FAXSession
 * FAXSessions
 * FAXStats
 * Filter
 * GetConfig
 * GetConfigJSON
 * GetVar
 * Hangup
 * IAXnetstats
 * IAXpeerlist
 * IAXpeers
 * IAXregistry
 * JabberSend
 * ListCategories
 * ListCommands
 * LocalOptimizeAway
 * LoggerRotate
 * Login
 * Logoff
 * MailboxCount
 * MailboxStatus
 * MeetmeList
 * MeetmeListRooms
 * MeetmeMute
 * MeetmeUnmute
 * MixMonitor
 * MixMonitorMute
 * ModuleCheck
 * ModuleLoad
 * ModuleReload
 * ModuleUnload
 * Monitor
 * MuteAudio
 * MWIDelete
 * MWIGet
 * MWIUpdate
 * Originate
 * Park
 * ParkedCalls
 * Parkinglots
 * PauseMonitor
 * Ping
 * PJSIPNotify
 * PJSIPQualify
 * PJSIPRegister
 * PJSIPShowAors
 * PJSIPShowAuths
 * PJSIPShowContacts
 * PJSIPShowEndpoint
 * PJSIPShowEndpoints
 * PJSIPShowRegistrationInboundContactStatuses
 * PJSIPShowRegistrationsInbound
 * PJSIPShowRegistrationsOutbound
 * PJSIPShowResourceLists
 * PJSIPShowSubscriptionsInbound
 * PJSIPShowSubscriptionsOutbound
 * PJSIPUnregister
 * PlayDTMF
 * PresenceState
 * PresenceStateList
 * PRIDebugFileSet
 * PRIDebugFileUnset
 * PRIDebugSet
 * PRIShowSpans
 * QueueAdd
 * QueueChangePriorityCaller
 * QueueLog
 * QueueMemberRingInUse
 * QueueMemberRingInUse.php
 * QueuePause
 * QueuePenalty
 * QueueReload
 * QueueRemove
 * QueueReset
 * QueueRule
 * Queues
 * QueueStatus
 * QueueSummary
 * QueueUnpause
 * Redirect
 * Reload
 * SCCPAnswerCall
 * SCCPConference
 * SCCPConfigMetaData
 * SCCPDeviceAddLine
 * SCCPDeviceRestart
 * SCCPDeviceSetDND
 * SCCPDeviceUpdate
 * SCCPDndDevice
 * SCCPHangupCall
 * SCCPHoldCall
 * SCCPLineForwardUpdate
 * SCCPMessageDevice
 * SCCPMessageDevices
 * SCCPMicrophone
 * SCCPShowChannels
 * SCCPShowConference
 * SCCPShowConferences
 * SCCPShowDevice
 * SCCPShowDevices
 * SCCPShowGlobals
 * SCCPShowHintLineStates
 * SCCPShowHintSubscriptions
 * SCCPShowLine
 * SCCPShowLines
 * SCCPShowMWISubscriptions
 * SCCPShowSessions
 * SCCPShowSoftkeySets
 * SCCPStartCall
 * SCCPSystemMessage
 * SCCPTokenAck
 * SendText
 * SetVar
 * ShowDialPlan
 * SIPNotify
 * SIPPeers
 * SIPpeerstatus
 * SIPQualifyPeer
 * SIPShowPeer
 * SIPShowRegistry
 * SKINNYdevices
 * SKINNYlines
 * SKINNYshowdevice
 * SKINNYshowline
 * SorceryMemoryCacheExpire
 * SorceryMemoryCacheExpireObject
 * SorceryMemoryCachePopulate
 * SorceryMemoryCacheStale
 * SorceryMemoryCacheStaleObject
 * Status
 * StopMixMonitor
 * StopMonitor
 * UnpauseMonitor
 * UpdateConfig
 * UserEvent
 * VGSMSMSTx
 * VoicemailRefresh
 * VoicemailUsersList
 * VoicemailUserStatus
 * WaitEvent

## Debugging, logging

You can optionally set a [PSR-3](http://www.php-fig.org/psr/psr-3/) compatible logger:
```php
$pami->setLogger($logger);
```

By default, the client will use the [NullLogger](http://www.php-fig.org/psr/psr-3/#1-4-helper-classes-and-interfaces).

# Developers
The upstream development tooling uses [phing](https://www.phing.info/). Current tasks include:
 * test: Runs [PHPUnit](https://phpunit.de/).
 * cs: Runs [CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer).
 * doc: Runs [PhpDocumentor](http://www.phpdoc.org/).
 * md: runs [PHPMD](http://phpmd.org/).
 * build: This is the default task, and will run all the other tasks.

## Running a phing task
To run a task, just do:

```sh
vendor/bin/phing build
```

## Contributing
To contribute:
 * Make sure you open a **concise** and **short** pull request.
 * Throw in any needed unit tests to accomodate the new code or the
 changes involved.
 * Run `phing` and make sure everything is ok before submitting the pull
 request (make phpmd and CodeSniffer happy, also make sure that phpDocumentor
 does not throw any warnings, since all our documentation is automatically
 generated).
 * Your code must comply with [PSR-2](http://www.php-fig.org/psr/psr-2/),
 CodeSniffer should take care of that.

LICENSE
=======
Copyright 2016 Marcelo Gornstein <marcelog@gmail.com>

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License.

# Maintainer of the upstream chan-sccp fork:

* Diederik de Groot <ddegroot@talon.nl>

* Added the SCCP Message Implementation.
* ResponseHandling for ReponseEvents returned from ActionMessages that
  return multiple/complex results [Orig PR:73](https://github.com/marcelog/PAMI/pull/73).
* Note: Had some help from Jacob Kiers on the ResponseHandling.

# Thanks To:

* First a formost Marcelo Gornstein, the original designer and developer of
this module. See:[https://github.com/marcelog/PAMI](https://github.com/marcelog/PAMI)
for more information

* Jason Blank <rumpled at github> for helping in the debugging of the queue
functionality and some other ami inconsistencies.

* Francesco Usseglio Gaudi, for help in debugging the Originate action.

* Matías Barletta, for the vgms support.

* Eli Hunter, for helping in bringing in tls compatibility.

* Freddy dafredmail at googlemail, for his help and testing environment to add
dongle support.

* Joshua Elson for his help in trying and debugging in loaded asterisk servers.

* Jacob Kiers for his help in bringing in and testing async agi functionality,
and CEL event support.

* Richard Baar for noticing the lack of eof support when reading from socket,
the JabberEvent, and the ScreenName in JabberAction.

* Scot Opell for helping in debugging stream_get_line() in 5.3.9 and 5.3.10.

* Brian (wormling) for trying and fixing bugs on asyncagi.

* Henning Bragge for helping with newstate event and queues.

* mbonneau for ParkedCall and UnParkedCall events.

* @brenard : Updates to ConfBridge. See:[Orig PR:179](https://github.com/marcelog/PAMI/pull/179)

* @NikolayRevin: Add action QueueMemberRingInUse and updated: QueueMemberEvent and
QueueParamsEvent. See:[Orig PR:177](https://github.com/marcelog/PAMI/pull/177).

* @alexmnv: Added `getSocket()` method to `ClientImpl` class. See:[Orig
  PR:169](https://github.com/marcelog/PAMI/pull/169).

* @edigomes: Added Options-XXXXXX. See:[Orig PR:162](https://github.com/marcelog/PAMI/pull/162).

* @amir200xven: Added CDR EVent. See:[Orig PR:159](https://github.com/marcelog/PAMI/pull/159).

* @wizzle: Added PJSIPShowEndpoints et al. See:[Orig PR:158](https://github.com/marcelog/PAMI/pull/158)
and [Orig PR:157](https://github.com/marcelog/PAMI/pull/157).

* @syco: Extended MonitorAction. See:[Orig PR:149](https://github.com/marcelog/PAMI/pull/149).

* @ilgiz-badamshin: Extended AsyncAgi impl. See:[Orig
  PR:143](https://github.com/marcelog/PAMI/pull/143).

* @Adrian0350: Added DAHDIChannelEvent. See:[Orig
  PR:138](https://github.com/marcelog/PAMI/pull/138).

* @Bloodoff: Added DeviceStateChange/VarSet. See:[Orig
  PR:126](https://github.com/marcelog/PAMI/pull/126).

* @thomasvargiu: Fix getMessages. See:[Orig PR:122](https://github.com/marcelog/PAMI/pull/122).

* @sctt: Added event filters. See:[Orig PR:107](https://github.com/marcelog/PAMI/pull/107).

* @alesf: Added QueueEntry. See:[Orig PR:98](https://github.com/marcelog/PAMI/pull/98).

* @parhamdoustdar: ConfBridge et al. See:[Orig PR:80](https://github.com/marcelog/PAMI/pull/80).
