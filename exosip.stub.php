<?php
/**
 * PHP ExoSip Extension Stub File
 * 
 * This file provides IDE autocomplete and type hints for the ExoSip extension.
 * DO NOT include this file in your code - it's only for IDE support.
 * 
 * @package ExoSip
 * @version 2.0.0
 */

/**
 * Universal SIP Server Class
 * 
 * Pure OOP interface for building SIP-based applications.
 * Supports event-driven architecture with full RFC 3261 compliance.
 * 
 * @example
 * ```php
 * $sip = new ExoSip([
 *     'host' => '0.0.0.0',
 *     'port' => 5060,
 *     'mode' => 'TCP'  // TCP|UDP|ALL (case-insensitive)
 * ]);
 * 
 * $sip->onRegister = fn($event) => handleRegister($event);
 * $sip->onMessage = fn($event) => handleMessage($event);
 * $sip->run();
 * ```
 */
class ExoSip {
    
    /* ========== Core SIP Event Handlers (RFC 3261) ========== */
    
    /**
     * REGISTER event handler - Device/user registration
     * @var callable(SipEvent): void|bool|null
     */
    public $onRegister;
    
    /**
     * INVITE event handler - Session invitation
     * @var callable(SipEvent): void|bool|null
     */
    public $onInvite;
    
    /**
     * ACK event handler - Final response acknowledgment
     * @var callable(SipEvent): void|bool|null
     */
    public $onAck;
    
    /**
     * BYE event handler - Session termination
     * @var callable(SipEvent): void|bool|null
     */
    public $onBye;
    
    /**
     * CANCEL event handler - Request cancellation
     * @var callable(SipEvent): void|bool|null
     */
    public $onCancel;
    
    /**
     * OPTIONS event handler - Capability query
     * @var callable(SipEvent): void|bool|null
     */
    public $onOptions;
    
    /* ========== SIP Extension Methods ========== */
    
    /**
     * MESSAGE event handler - SIP instant messaging (RFC 3428)
     * Used in GB28181 for keepalive, device info, etc.
     * @var callable(SipEvent): void|bool|null
     */
    public $onMessage;
    
    /**
     * INFO event handler - Mid-session information (RFC 6086)
     * @var callable(SipEvent): void|bool|null
     */
    public $onInfo;
    
    /**
     * UPDATE event handler - Session modification (RFC 3311)
     * @var callable(SipEvent): void|bool|null
     */
    public $onUpdate;
    
    /**
     * PRACK event handler - Provisional response acknowledgment (RFC 3262)
     * @var callable(SipEvent): void|bool|null
     */
    public $onPrack;
    
    /**
     * REFER event handler - Call transfer (RFC 3515)
     * @var callable(SipEvent): void|bool|null
     */
    public $onRefer;
    
    /* ========== Publish-Subscribe Events (RFC 3903, RFC 3856) ========== */
    
    /**
     * SUBSCRIBE event handler - Event subscription
     * @var callable(SipEvent): void|bool|null
     */
    public $onSubscribe;
    
    /**
     * NOTIFY event handler - Event notification
     * @var callable(SipEvent): void|bool|null
     */
    public $onNotify;
    
    /**
     * PUBLISH event handler - Presence/state publication
     * @var callable(SipEvent): void|bool|null
     */
    public $onPublish;
    
    /* ========== Response and Error Handling ========== */
    
    /**
     * Response event handler - SIP responses (1xx-6xx)
     * @var callable(SipEvent): void|bool|null
     */
    public $onResponse;
    
    /**
     * Timeout event handler - Request timeout
     * @var callable(SipEvent): void|bool|null
     */
    public $onTimeout;
    
    /**
     * Error event handler - Protocol errors
     * @var callable(SipEvent): void|bool|null
     */
    public $onError;
    
    /* ========== Connection Management ========== */
    
    /**
     * Connection established handler (TCP/TLS)
     * @var callable(SipEvent): void|bool|null
     */
    public $onConnect;
    
    /**
     * Connection closed handler
     * @var callable(SipEvent): void|bool|null
     */
    public $onClose;
    
    /* ========== Master-Worker-Task Callbacks ========== */
    
    /**
     * Task handler - Execute async tasks in Task process
     * Runs in Task process, handles time-consuming operations (HTTP, DB, Redis)
     * @var callable(int $taskId, array $data): mixed
     * 
     * @example
     * ```php
     * $sip->onTask = function($taskId, $data) {
     *     $type = $data['type'] ?? 'unknown';
     *     $payload = $data['payload'] ?? [];
     *     
     *     switch ($type) {
     *         case 'webhook':
     *             $result = file_get_contents($payload['url'], false, stream_context_create([
     *                 'http' => ['method' => 'POST', 'timeout' => 5]
     *             ]));
     *             return ['success' => true, 'response' => $result];
     *             
     *         case 'save_catalog':
     *             $db = new PDO(...);
     *             $stmt = $db->prepare("INSERT INTO catalog ...");
     *             $stmt->execute($payload);
     *             return ['success' => true];
     *             
     *         default:
     *             return ['success' => false, 'error' => 'Unknown task'];
     *     }
     * };
     * ```
     */
    public $onTask;
    
    /**
     * Task finish handler - Receive task results in Worker process
     * Runs in Worker process, automatically triggered when onTask returns
     * @var callable(int $taskId, mixed $result): void
     * 
     * @example
     * ```php
     * $sip->onTaskFinish = function($taskId, $result) {
     *     if ($result['success']) {
     *         echo "Task #{$taskId} completed successfully\n";
     *     } else {
     *         echo "Task #{$taskId} failed: {$result['error']}\n";
     *     }
     * };
     * ```
     */
    public $onTaskFinish;
    
    /**
     * Timer handler - Periodic execution in Worker process
     * Runs in Worker process, triggered at configured interval
     * @var callable(): bool
     * 
     * @example
     * ```php
     * $sip->onTimer = function() use ($gb28181) {
     *     // Check device timeouts
     *     $timeoutDevices = $gb28181->processTimeouts();
     *     
     *     // Cleanup expired data
     *     $gb28181->cleanupExpiredData();
     *     
     *     return true;  // Continue timer, false to stop
     * };
     * ```
     */
    public $onTimer;
    
    /**
     * Pipe message handler - Receive messages from Task process (Task→Worker communication)
     * Runs in Worker process, triggered when Task calls sendToWorker()
     * @var callable(mixed $data): void
     * 
     * @example
     * ```php
     * $sip->onPipeMessage = function($server, $data) {
     *     $type = $data['type'] ?? 'unknown';
     *     
     *     switch ($type) {
     *         case 'device_info':
     *             // Handle device info pushed from Task
     *             echo "Device: {$data['device_id']}\n";
     *             break;
     *             
     *         case 'progress':
     *             // Handle progress updates
     *             echo "Progress: {$data['percentage']}%\n";
     *             break;
     *     }
     * };
     * ```
     */
    public $onPipeMessage;
    
    /**
     * Worker start handler - Called when Worker process starts
     * Ideal for initializing resources, starting long-running tasks, etc.
     * @var callable(ExoSip $server): void
     * 
     * @example
     * ```php
     * $sip->onWorkerStart = function($server) {
     *     echo "Worker started, PID: " . posix_getpid() . "\n";
     *     
     *     // Start long-running subscriber task (e.g., Redis subscribe)
     *     $server->startLongTask(function($server) {
     *         $redis = new Redis();
     *         $redis->pconnect('127.0.0.1', 6379);
     *         
     *         // This will block forever - that's OK in a long task!
     *         $redis->subscribe(['gb28181:commands'], function($redis, $channel, $msg) use ($server) {
     *             $data = json_decode($msg, true);
     *             // Forward to Worker
     *             $server->sendToWorker(['type' => 'redis_cmd', 'data' => $data]);
     *         });
     *     });
     * };
     * ```
     */
    public $onWorkerStart;
    
    /**
     * Create and optionally initialize SIP server
     * 
     * @param array|null $config Optional configuration:
     *   - host: string - Listen IP address (default: '0.0.0.0')
     *   - port: int - Listen port (default: 5060)
     *   - mode: string - Transport protocol: 'UDP'|'TCP'|'ALL' (case-insensitive, default: 'UDP')
     *   - ua: string - User-Agent string (default: 'PHP-GB28181')
     *   - sipId: string - SIP server ID for authentication
     *   - sipRealm: string - SIP authentication realm/domain
     *   - sipPass: string - SIP authentication password
     *   - sipTimeout: int - Transaction timeout in seconds (default: 30)
     *   - sipExpiry: int - Registration expiry in seconds (default: 3600)
     *   - public_ip: string - Public IP address (default: auto-detect)
     * 
     * Master-Worker-Task Configuration (optional):
     *   - task_worker_num: int - Number of Task processes (default: 4)
     *   - timer_interval: int - Timer interval in milliseconds (default: 1000)
     *   - pid_file: string - PID file path (e.g., '/tmp/server.pid')
     * 
     * @example
     * ```php
     * // Single process mode
     * $sip = new ExoSip([
     *     'host' => '0.0.0.0',
     *     'port' => 5060,
     *     'mode' => 'TCP',
     *     'sipId' => '34020000002000000001'
     * ]);
     * 
     * // Master-Worker-Task mode
     * $sip = new ExoSip([
     *     'host' => '0.0.0.0',
     *     'port' => 5060,
     *     'mode' => 'UDP',
     *     'task_worker_num' => 4,
     *     'timer_interval' => 30000,  // 30 seconds
     *     'pid_file' => '/tmp/gb28181_server.pid',
     * ]);
     * ```
     */
    public function __construct(?array $config = null) {}
    
    /**
     * Initialize or re-initialize SIP server
     * 
     * Used for manual initialization or hot-restart scenarios.
     * 
     * @param array $config Configuration array (same as constructor)
     * @return bool True on success, false on failure
     */
    public function init(array $config): bool {}
    
    /**
     * Shutdown and cleanup SIP context
     * 
     * Use this for graceful shutdown or before re-initialization.
     * The event loop (run()) will stop automatically.
     * 
     * @return bool Always returns true
     */
    public function quit(): bool {}
    
    /**
     * Process pending SIP events (non-blocking mode)
     * 
     * For custom event loops or integration with frameworks.
     * 
     * @param int $timeout_ms Timeout in milliseconds (0 = immediate return)
     * @return SipEvent[] Array of SipEvent objects
     * 
     * @example
     * ```php
     * while (true) {
     *     $events = $sip->processEvents(100);
     *     foreach ($events as $event) {
     *         handleEvent($event);
     *     }
     * }
     * ```
     */
    public function processEvents(int $timeout_ms = 0): array {}
    
    /**
     * Run SIP server with automatic event dispatching (blocking)
     * 
     * Starts the event loop and dispatches events to registered handlers.
     * Blocks until stop() is called or SIGINT/SIGTERM is received.
     * 
     * @return bool True when stopped gracefully
     * 
     * @example
     * ```php
     * $sip->onRegister = fn($e) => handleRegister($e);
     * $sip->onMessage = fn($e) => handleMessage($e);
     * $sip->run();  // Blocks here
     * ```
     */
    public function run(): bool {}
    
    /**
     * Send SIP MESSAGE request
     * 
     * Used for out-of-dialog messages (GB28181 commands, instant messaging, etc.)
     * 
     * @param string $to Target SIP URI (e.g., 'sip:34020000001320000001@3402000000')
     * @param string $message Message body content
     * @param string|null $contentType Content-Type header (default: 'Application/MANSCDP+xml')
     * @return integer
     * 
     * @example
     * ```php
     * // GB28181 catalog query
     * $xml = "<?xml version=\"1.0\"?><Query><CmdType>Catalog</CmdType>...</Query>";
     * $sip->sendMessage('sip:34020000001320000001@3402000000', $xml, 'Application/MANSCDP+xml');
     * ```
     */
    public function sendMessage(string $to, string $message, ?string $contentType = null): integer {}


    /**
     * Send SIP ACK request
     * @param int $dialogId Dialog ID
     * @return bool
     */
    public function sendAck(int $dialogId): bool {}
    
    /**
     * Send SIP INFO request (Playback Control - GB28181)
     * 
     * Sends an INFO request within an established INVITE dialog for playback control.
     * This is the correct method for GB28181 playback control (pause, resume, seek, speed).
     * 
     * ⚠️ **Important**: INFO is different from MESSAGE:
     * - INFO: Sent within an established dialog (requires dialog_id from INVITE)
     * - MESSAGE: Sent outside a dialog (standalone request)
     * 
     * ## MANSRTSP Protocol (GB28181 Annex B)
     * 
     * GB28181 defines a subset of RTSP for playback control called MANSRTSP.
     * The Content-Type MUST be `Application/MANSRTSP`.
     * 
     * Supported commands:
     * - **PAUSE**: Pause playback
     * - **PLAY**: Resume or seek playback, or change speed
     * - **TEARDOWN**: Stop playback (same as BYE)
     * 
     * @param int $dialogId Dialog ID from established INVITE session
     * @param string $body MANSRTSP command body
     * @param string|null $contentType Content-Type (default: 'Application/MANSRTSP')
     * @return bool True on success, false on failure
     * 
     * @example Pause playback
     * ```php
     * $body = "PAUSE RTSP/1.0\r\n"
     *       . "CSeq: 1\r\n"
     *       . "PauseTime: now\r\n";
     * 
     * $sip->sendInfo($dialogId, $body, 'Application/MANSRTSP');
     * ```
     * 
     * @example Resume playback
     * ```php
     * $body = "PLAY RTSP/1.0\r\n"
     *       . "CSeq: 2\r\n"
     *       . "Range: npt=now-\r\n";
     * 
     * $sip->sendInfo($dialogId, $body);
     * ```
     * 
     * @example Seek to position (300 seconds from start)
     * ```php
     * $seekTime = 300;  // seconds
     * $body = "PLAY RTSP/1.0\r\n"
     *       . "CSeq: 3\r\n"
     *       . "Range: npt={$seekTime}-\r\n";
     * 
     * $sip->sendInfo($dialogId, $body);
     * ```
     * 
     * @example Speed control (2x fast forward)
     * ```php
     * $speed = 2.0;
     * $body = "PLAY RTSP/1.0\r\n"
     *       . "CSeq: 4\r\n"
     *       . "Scale: " . sprintf("%.6f", $speed) . "\r\n";
     * 
     * $sip->sendInfo($dialogId, $body);
     * ```
     * 
     * @example Speed control (0.5x slow motion)
     * ```php
     * $speed = 0.5;
     * $body = "PLAY RTSP/1.0\r\n"
     *       . "CSeq: 5\r\n"
     *       . "Scale: " . sprintf("%.6f", $speed) . "\r\n";
     * 
     * $sip->sendInfo($dialogId, $body);
     * ```
     * 
     * @see WVP-PRO SIPCommander.playbackControlCmd() for reference implementation
     * @see GB/T 28181-2016 Annex B for MANSRTSP protocol specification
     */
    public function sendInfo(int $dialogId, string $body, ?string $contentType = null): bool {}
    
    /**
     * Send SIP INVITE request (Server-side)
     * 
     * Initiates a SIP dialog/call session. Used in GB28181 for:
     * - Real-time video streaming (live video)
     * - Playback of recorded video
     * - Voice intercom
     * 
     * Returns call_id which can be used later for sendBye() to terminate the session.
     * 
     * @param string $to_uri Target SIP URI (e.g., 'sip:34020000001320000001@192.168.1.100:5060')
     * @param string $sdp SDP body describing media session
     * @param array|null $headers Optional SIP headers:
     *   - Subject: string - GB28181 Subject header (e.g., 'channelId:ssrc,serverId:0')
     *   - Content-Type: string - Usually 'application/sdp'
     *   - Contact: string - Contact URI
     * @return int Call ID (> 0) on success, -1 on failure
     * 
     * @example
     * ```php
     * // GB28181 real-time video INVITE
     * $sdp = "v=0\r\n"
     *     . "o=34020000002000000001 0 0 IN IP4 192.168.1.1\r\n"
     *     . "s=Play\r\n"
     *     . "c=IN IP4 192.168.1.1\r\n"
     *     . "t=0 0\r\n"
     *     . "m=video 30000 TCP/RTP/AVP 96\r\n"
     *     . "a=recvonly\r\n"
     *     . "a=rtpmap:96 PS/90000\r\n"
     *     . "y=0100000001\r\n";
     * 
     * $callId = $sip->sendInvite(
     *     'sip:34020000001320000001@192.168.1.100:5060',
     *     $sdp,
     *     [
     *         'Subject' => '34020000001320000001:0100000001,34020000002000000001:0',
     *         'Content-Type' => 'application/sdp'
     *     ]
     * );
     * 
     * if ($callId > 0) {
     *     echo "INVITE sent, call_id: {$callId}\n";
     *     // Store call_id for later use (e.g., to send BYE)
     *     $activeSessions[$channelId] = ['call_id' => $callId];
     * }
     * ```
     * 
     * @example
     * ```php
     * // Playback INVITE
     * $sdp = "v=0\r\n"
     *     . "o=34020000002000000001 0 0 IN IP4 192.168.1.1\r\n"
     *     . "s=Playback\r\n"  // Note: Playback not Play
     *     . "c=IN IP4 192.168.1.1\r\n"
     *     . "t=1640000000 1640003600\r\n"  // Start and end time
     *     . "m=video 30001 TCP/RTP/AVP 96\r\n"
     *     . "a=recvonly\r\n"
     *     . "y=0200000001\r\n";
     * 
     * $callId = $sip->sendInvite($targetUri, $sdp, ['Subject' => $subject]);
     * ```
     * 
     * @see sendBye() To terminate the session
     */
    public function sendInvite(string $to_uri, string $sdp, ?array $headers = null): int {}
    
    /**
     * Send SIP BYE request (Server-side)
     * 
     * Terminates an active SIP dialog/call session. Used to stop:
     * - Real-time video streaming
     * - Playback sessions
     * - Voice intercom
     * 
     * @param int $call_id Call ID returned by sendInvite()
     * @param int $dialog_id Dialog ID (usually 0 for simple sessions)
     * @return bool True on success, false on failure
     * 
     * @example
     * ```php
     * // Stop real-time video
     * $session = $activeSessions[$channelId];
     * if ($session) {
     *     $result = $sip->sendBye($session['call_id'], 0);
     *     if ($result) {
     *         echo "BYE sent successfully\n";
     *         unset($activeSessions[$channelId]);
     *     }
     * }
     * ```
     * 
     * @example
     * ```php
     * // Complete INVITE-BYE flow
     * class VideoSession {
     *     private $sip;
     *     private $callId;
     *     
     *     public function start($channelUri, $sdp) {
     *         $this->callId = $this->sip->sendInvite($channelUri, $sdp);
     *         return $this->callId > 0;
     *     }
     *     
     *     public function stop() {
     *         if ($this->callId > 0) {
     *             return $this->sip->sendBye($this->callId, 0);
     *         }
     *         return false;
     *     }
     * }
     * ```
     * 
     * @see sendInvite() To initiate a session
     */
    public function sendBye(int $call_id, int $dialog_id = -1): bool {}
    
    /**
     * Send SIP response to a request
     * 
     * Used in event handlers to respond to incoming requests.
     * 
     * @param int $tid Transaction ID from SipEvent::getTid()
     * @param int $code SIP response code:
     *   - 1xx: Provisional (100 Trying, 180 Ringing)
     *   - 2xx: Success (200 OK)
     *   - 3xx: Redirection (301 Moved Permanently)
     *   - 4xx: Client Error (400 Bad Request, 404 Not Found)
     *   - 5xx: Server Error (500 Internal Server Error)
     *   - 6xx: Global Failure (603 Decline)
     * @param string|null $reason Reason phrase (default: standard phrase for code)
     * @param array|null $headers Additional headers as key-value pairs (e.g., ['Expires' => 3600])
     * @return bool True on success, false on failure
     * 
     * @example
     * ```php
     * $sip->onRegister = function($event) use ($sip) {
     *     // Accept registration
     *     $sip->sendResponse($event->getTid(), 200, 'OK', ['Expires' => 3600]);
     * };
     * ```
     */
    public function sendResponse(int $tid, int $code, ?string $reason = null, ?array $headers = null): bool {}

    /**
     * Send SIP INVITE response (200 OK with SDP body)
     *
     * Used to respond to incoming INVITE requests (e.g., device-initiated broadcast INVITE).
     * Unlike sendResponse() which handles MESSAGE/REGISTER, this method uses the eXosip CALL API
     * to properly respond to INVITE requests with SDP body.
     *
     * @param int $tid Transaction ID from SipEvent::getTid()
     * @param int $code SIP response code (typically 200)
     * @param string|null $body SDP body content (required for 200 OK)
     * @param string|null $reason Reason phrase (default: standard phrase for code, e.g., "OK")
     * @param string|null $contentType Content-Type header (default: "application/sdp")
     * @return bool True on success, false on failure
     *
     * @example
     * ```php
     * // Respond to device broadcast INVITE with SDP
     * $sip->onInvite = function($event) use ($sip) {
     *     $sdp = SdpBuilder::buildBroadcastSdp($serverId, $mediaIp, $port, $ssrc);
     *     $sip->sendCallAnswer($event->getTid(), 200, $sdp, 'OK');
     * };
     * ```
     */
    public function sendCallAnswer(int $tid, int $code, ?string $body = null, ?string $reason = null, ?string $contentType = null): bool {}
    
    /* ========== SUBSCRIBE/NOTIFY Methods (GB28181订阅功能) ========== */
    
    /**
     * Send SIP SUBSCRIBE request (GB28181 event subscription)
     * 
     * Subscribe to device events such as:
     * - Catalog: Device directory changes (add/remove/update)
     * - Alarm: Alarm event notifications
     * - MobilePosition: GPS position updates
     * 
     * @param string $toUri Target device SIP URI (e.g., "sip:34020000001320000001@192.168.1.100:5060")
     * @param string $eventType Event type to subscribe:
     *   - "Catalog": Subscribe to device catalog changes
     *   - "Alarm": Subscribe to alarm notifications
     *   - "MobilePosition": Subscribe to GPS position updates
     * @param int $expires Subscription duration in seconds (default: 3600)
     * @param string|null $xmlBody Optional GB28181 XML body for query parameters
     * @return int|false Subscription ID on success (>0), false on failure
     * 
     * @example
     * ```php
     * // Subscribe to Catalog changes (目录订阅)
     * $subId = $sip->subscribe(
     *     "sip:34020000001320000001@192.168.1.100:5060",
     *     "Catalog",
     *     3600  // 1 hour
     * );
     * if ($subId !== false) {
     *     echo "Subscribed to Catalog, ID: {$subId}\n";
     * }
     * ```
     * 
     * @example
     * ```php
     * // Subscribe to Alarm notifications (报警订阅)
     * $xmlBody = '<?xml version="1.0"?>
     * <Query>
     *   <CmdType>Alarm</CmdType>
     *   <SN>1</SN>
     *   <DeviceID>34020000001320000001</DeviceID>
     *   <StartAlarmPriority>1</StartAlarmPriority>
     *   <EndAlarmPriority>4</EndAlarmPriority>
     * </Query>';
     * 
     * $subId = $sip->subscribe(
     *     $deviceUri,
     *     "Alarm",
     *     7200,  // 2 hours
     *     $xmlBody
     * );
     * ```
     * 
     * @example
     * ```php
     * // Subscribe to MobilePosition (移动位置订阅)
     * $xmlBody = '<?xml version="1.0"?>
     * <Query>
     *   <CmdType>MobilePosition</CmdType>
     *   <SN>1</SN>
     *   <DeviceID>34020000001320000001</DeviceID>
     *   <Interval>5</Interval>
     * </Query>';
     * 
     * $subId = $sip->subscribe($deviceUri, "MobilePosition", 3600, $xmlBody);
     * ```
     * 
     * @see refreshSubscribe() To extend subscription before expiry
     * @see cancelSubscribe() To terminate subscription
     * @see getSubscriptions() To list all active subscriptions
     */
    public function subscribe(string $toUri, string $eventType, int $expires = 3600, ?string $xmlBody = null): int|false {}
    
    /**
     * Refresh/extend an existing subscription (GB28181)
     * 
     * Sends SUBSCRIBE with same dialog to extend expiration time.
     * Should be called before subscription expires (typically at 80% of duration).
     * 
     * @param int $subscriptionId Subscription ID from subscribe() return value
     * @param int $expires New subscription duration in seconds (default: 3600)
     * @return bool True on success, false on failure
     * 
     * @example
     * ```php
     * // Auto-refresh subscription before expiry
     * $sip->onTimer = function() use ($sip, &$activeSubscriptions) {
     *     foreach ($activeSubscriptions as $deviceId => $subInfo) {
     *         $remaining = $subInfo['expires_at'] - time();
     *         // Refresh when 20% time remaining
     *         if ($remaining < $subInfo['duration'] * 0.2) {
     *             $result = $sip->refreshSubscribe($subInfo['subscription_id'], 3600);
     *             if ($result) {
     *                 $subInfo['expires_at'] = time() + 3600;
     *                 echo "Refreshed subscription for {$deviceId}\n";
     *             }
     *         }
     *     }
     * };
     * ```
     * 
     * @see subscribe() To create initial subscription
     */
    public function refreshSubscribe(int $subscriptionId, int $expires = 3600): bool {}
    
    /**
     * Cancel/terminate an active subscription (GB28181)
     * 
     * Sends SUBSCRIBE with Expires: 0 to terminate subscription.
     * The device will stop sending NOTIFY messages for this subscription.
     * 
     * @param int $subscriptionId Subscription ID from subscribe() return value
     * @return bool True on success, false on failure
     * 
     * @example
     * ```php
     * // Cancel subscription when device goes offline
     * $sip->onRegister = function($event) use ($sip, &$subscriptions) {
     *     $expires = $event->getExpires();
     *     if ($expires === 0) {
     *         // Device unregistering
     *         $deviceId = extractDeviceId($event->getFromUri());
     *         if (isset($subscriptions[$deviceId])) {
     *             $sip->cancelSubscribe($subscriptions[$deviceId]);
     *             unset($subscriptions[$deviceId]);
     *             echo "Cancelled subscription for {$deviceId}\n";
     *         }
     *     }
     * };
     * ```
     * 
     * @see subscribe() To create subscription
     */
    public function cancelSubscribe(int $subscriptionId): bool {}
    
    /**
     * Send response to incoming NOTIFY request (GB28181)
     * 
     * When device sends NOTIFY (catalog change, alarm, position update),
     * server MUST respond with 200 OK to acknowledge receipt.
     * 
     * @param int $tid Transaction ID from the NOTIFY event
     * @param int $code SIP response code (typically 200 for success)
     * @return bool True on success, false on failure
     * 
     * @example
     * ```php
     * // Handle incoming NOTIFY
     * $sip->onNotify = function($event) use ($sip, $deviceManager) {
     *     $body = $event->getBody();
     *     $eventType = $event->getEventType();  // Catalog, Alarm, MobilePosition
     *     
     *     switch ($eventType) {
     *         case 'Catalog':
     *             // Parse catalog change XML
     *             $catalog = parseCatalogNotify($body);
     *             $deviceManager->updateCatalog($catalog);
     *             break;
     *             
     *         case 'Alarm':
     *             // Parse alarm notification
     *             $alarm = parseAlarmNotify($body);
     *             $deviceManager->handleAlarm($alarm);
     *             break;
     *             
     *         case 'MobilePosition':
     *             // Parse GPS position
     *             $position = parsePositionNotify($body);
     *             $deviceManager->updatePosition($position);
     *             break;
     *     }
     *     
     *     // IMPORTANT: Always respond to NOTIFY
     *     $sip->sendNotifyResponse($event->getTid(), 200);
     * };
     * ```
     * 
     * @see subscribe() To create subscription that triggers NOTIFY
     */
    public function sendNotifyResponse(int $tid, int $code): bool {}
    
    /**
     * Send NOTIFY request to subscriber (as event source) (GB28181)
     * 
     * When platform is the event source (e.g., device catalog changed, alarm occurred),
     * it sends NOTIFY to all subscribers to inform them of the event.
     * 
     * This is the OPPOSITE of sendNotifyResponse():
     * - sendNotifyResponse(): Respond to incoming NOTIFY from devices
     * - sendNotify(): Send outgoing NOTIFY to subscribers
     * 
     * GB28181 Subscription States:
     * - "active": Subscription is valid and active
     * - "pending": Subscription is being processed
     * - "terminated": Subscription has ended (use with reason parameter)
     * 
     * Termination Reasons (when state is "terminated"):
     * - "deactivated": Subscriber deactivated
     * - "probation": Subscription on probation
     * - "rejected": Subscription rejected
     * - "timeout": Subscription timed out
     * - "giveup": Server gave up
     * - "noresource": Resource no longer exists
     * 
     * @param int $dialogId Dialog ID from the subscription (from onSubscribe event)
     * @param string $subscriptionState Subscription state: "active", "pending", or "terminated"
     * @param string $body XML body (GB28181 MANSCDP format)
     * @param string|null $reason Termination reason (only when state is "terminated")
     * @return bool True on success, false on failure
     * 
     * @example
     * ```php
     * // When device catalog changes, notify all subscribers
     * $catalogXml = buildCatalogNotifyXml($deviceId, $channels);
     * 
     * foreach ($subscriptionManager->getCatalogSubscribers($deviceId) as $sub) {
     *     $sip->sendNotify(
     *         $sub['dialog_id'],
     *         'active',           // Subscription still valid
     *         $catalogXml,
     *         null                // No termination reason
     *     );
     * }
     * 
     * // When subscription expires, send terminated NOTIFY
     * $sip->sendNotify(
     *     $dialogId,
     *     'terminated',
     *     $finalXml,
     *     'timeout'           // Reason: subscription timed out
     * );
     * ```
     * 
     * @see subscribe() To create outgoing subscription
     * @see sendNotifyResponse() To respond to incoming NOTIFY
     */
    public function sendNotify(int $dialogId, string $subscriptionState, string $body, ?string $reason = null): bool {}
    
    /**
     * Get socket file descriptor for external event loops
     * 
     * Advanced usage: integrate with select()/poll()/epoll().
     * 
     * @return int Socket file descriptor, or -1 if not available
     */
    public function getFd(): int {}
    
    /**
     * Stop the SIP server gracefully
     * 
     * Signals the run() loop to exit. Non-blocking.
     * 
     * @return bool Always returns true
     */
    public function stop(): bool {}
    
    /**
     * Check if server event loop is running
     * 
     * @return bool True if run() is active, false otherwise
     */
    public function isRunning(): bool {}
    
    /**
     * Set server configuration (batch update)
     * 
     * @param array $config Configuration key-value pairs
     * @return bool True on success
     * 
     * @example
     * ```php
     * $sip->setConfig([
     *     'max_sessions' => 1000,
     *     'timeout' => 60
     * ]);
     * ```
     */
    public function setConfig(array $config): bool {}
    
    /**
     * Get configuration value(s)
     * 
     * @param string|null $key Configuration key (null = return all)
     * @return mixed|array|null Single value, all config, or null if key not found
     * 
     * @example
     * ```php
     * $port = $sip->getConfig('port');  // Get single value
     * $all = $sip->getConfig();         // Get all config
     * ```
     */
    public function getConfig(?string $key = null) {}
    
    /**
     * Get server runtime statistics
     * 
     * @return array{
     *   running: bool,
     *   uptime: int,
     *   listen_ip: string,
     *   listen_port: int,
     *   transport: string,
     *   config_items: int,
     *   event_handlers: array<string, bool>
     * } Statistics array
     * 
     * @example
     * ```php
     * $stats = $sip->getStats();
     * echo "Server running for {$stats['uptime']} seconds\n";
     * echo "Listening on {$stats['listen_ip']}:{$stats['listen_port']}\n";
     * ```
     */
    public function getStats(): array {}
    
    /* ========== Master-Worker-Task Methods ========== */
    
    /**
     * Add async task to Task process pool (Worker process only)
     * 
     * @param array $data Task data (must be array, will be passed to onTask callback)
     * @return int Task ID (auto-increment integer), or -1 on failure
     * 
     * @throws Exception If called in Master or Task process
     * 
     * @example
     * ```php
     * // In SIP event handler (Worker process)
     * $sip->onRegister = function($event) use ($sip) {
     *     $deviceId = extractDeviceId($event->getFromUri());
     *     
     *     // Post webhook task
     *     $taskId = $sip->addTask([
     *         'type' => 'webhook',
     *         'payload' => [
     *             'url' => 'http://api.example.com/device/register',
     *             'data' => [
     *                 'device_id' => $deviceId,
     *                 'timestamp' => time(),
     *             ]
     *         ]
     *     ]);
     *     
     *     echo "Task #{$taskId} posted\n";
     *     
     *     // Continue processing (non-blocking)
     *     $sip->sendResponse($event->getTid(), 200, 'OK');
     * };
     * ```
     */
    public function addTask(array $data): int {}
    
    /**
     * Send data to Worker process (Task process only)
     * 
     * Allows Task processes to proactively push messages to Worker.
     * Used for real-time notifications, progress updates, etc.
     * 
     * @param mixed $data Data to send (will be serialized)
     * @return bool True on success, false on failure
     * @throws Exception If called from non-Task process
     * 
     * @example
     * ```php
     * // In Task process (onTask callback)
     * $sip->onTask = function($server, $taskId, $data) {
     *     // Do some work
     *     $result = queryDatabase($data['id']);
     *     
     *     // Push result to Worker immediately (don't wait for return)
     *     $server->sendToWorker([
     *         'type' => 'db_result',
     *         'data' => $result,
     *         'timestamp' => time()
     *     ]);
     *     
     *     // Continue processing...
     *     $moreData = callExternalAPI();
     *     
     *     // Push progress update
     *     $server->sendToWorker([
     *         'type' => 'progress',
     *         'percentage' => 50
     *     ]);
     *     
     *     return ['status' => 'success'];
     * };
     * 
     * // In Worker process (onPipeMessage callback)
     * $sip->onPipeMessage = function($server, $data) {
     *     if ($data['type'] === 'db_result') {
     *         // Handle database result
     *         processResult($data['data']);
     *     } else if ($data['type'] === 'progress') {
     *         echo "Progress: {$data['percentage']}%\n";
     *     }
     * };
     * ```
     */
    public function sendToWorker($data): bool {}
    
    /**
     * Start a long-running task (Worker process only, called from onWorkerStart)
     * 
     * Creates a dedicated task process that is allowed to block indefinitely.
     * Unlike normal tasks (addTask), long tasks can run forever without blocking
     * the regular task pool. Perfect for Redis subscriptions, message queues, etc.
     * 
     * Key features:
     * - Runs in a separate dedicated process
     * - Can block forever (e.g., Redis::subscribe)
     * - Can use sendToWorker() to push messages to Worker
     * - Does NOT affect normal task processing
     * - Should only be called from onWorkerStart callback
     * 
     * @param callable $callback Task function to execute
     * @return bool True on success, false on failure
     * @throws Exception If called outside onWorkerStart
     * 
     * @example
     * ```php
     * // Redis subscriber example
     * $sip->onWorkerStart = function($server) {
     *     $server->startLongTask(function($server) {
     *         $redis = new Redis();
     *         $redis->pconnect('127.0.0.1', 6379);
     *         
     *         // This blocks forever - that's OK!
     *         $redis->subscribe(['gb28181:commands'], function($redis, $channel, $message) use ($server) {
     *             $data = json_decode($message, true);
     *             
     *             // Forward to Worker for processing
     *             $server->sendToWorker([
     *                 'type' => 'redis_command',
     *                 'data' => $data
     *             ]);
     *         });
     *     });
     * };
     * 
     * // Worker receives messages via onPipeMessage
     * $sip->onPipeMessage = function($server, $message) {
     *     if ($message['type'] === 'redis_command') {
     *         $data = $message['data'];
     *         
     *         // Handle command (SIP operations, etc.)
     *         if ($data['type'] === 'ptz_control') {
     *             sendPtzCommand($data['device_id'], $data['command']);
     *         }
     *     }
     * };
     * ```
     * 
     * @example
     * ```php
     * // Kafka consumer example
     * $sip->onWorkerStart = function($server) {
     *     $server->startLongTask(function($server) {
     *         $consumer = new RdKafka\Consumer();
     *         $consumer->subscribe(['device-events']);
     *         
     *         while (true) {
     *             $message = $consumer->consume(120 * 1000); // Blocks
     *             
     *             if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
     *                 $server->sendToWorker([
     *                     'type' => 'kafka_message',
     *                     'data' => json_decode($message->payload, true)
     *                 ]);
     *             }
     *         }
     *     });
     * };
     * ```
     */
    public function startLongTask(callable $callback): bool {}
    
    /**
     * Get process status (internal call, from running process)
     * 
     * @return array Process status information:
     *   - master: ['pid' => int, 'status' => string]
     *   - worker: ['pid' => int, 'status' => string, 'uptime' => int]
     *   - tasks: [['id' => int, 'pid' => int, 'status' => string], ...]
     *   - current_process: string - 'master'|'worker'|'task-N'
     *   - tasks_posted: int - Total tasks posted
     *   - tasks_failed: int - Failed tasks count
     * 
     * @example
     * ```php
     * $status = $sip->getProcessStatus();
     * 
     * echo "Current process: {$status['current_process']}\n";
     * echo "Master PID: {$status['master']['pid']}\n";
     * echo "Worker PID: {$status['worker']['pid']}\n";
     * echo "Tasks posted: {$status['tasks_posted']}\n";
     * 
     * foreach ($status['tasks'] as $task) {
     *     echo "Task-{$task['id']}: PID {$task['pid']}, {$task['status']}\n";
     * }
     * ```
     */
    public function getProcessStatus(): array {}
    
    /* ========== SDP Parser (Native osip2) ========== */
    
    /**
     * Parse SDP body using native osip2 parser
     * 
     * Production-grade SDP parser with GB28181 extension support.
     * Performance: 10-20x faster than PHP regex (0.05ms vs 0.5-1ms).
     * 
     * Features:
     * - RFC 4566 compliant parsing
     * - Automatic GB28181 extension extraction (y=/f= fields)
     * - Multi-media stream support (video + audio)
     * - Comprehensive attribute parsing
     * - Robust error handling
     * 
     * @param string $sdp SDP body string (must use \r\n line endings)
     * @return array|null Parsed SDP structure, or null on error
     * 
     * Return structure:
     * ```php
     * [
     *   'version' => '0',
     *   'origin' => [
     *     'username' => '34020000001320000001',
     *     'sess_id' => '0',
     *     'sess_version' => '0',
     *     'nettype' => 'IN',
     *     'addrtype' => 'IP4',
     *     'addr' => '192.168.1.100'
     *   ],
     *   'session_name' => 'Play',
     *   'connection' => [
     *     'c_nettype' => 'IN',
     *     'c_addrtype' => 'IP4',
     *     'addr' => '192.168.1.100'  // Note: 'addr' not 'address'
     *   ],
     *   'medias' => [  // Array of media streams
     *     [
     *       'media' => 'video',
     *       'port' => 6000,
     *       'proto' => 'RTP/AVP',  // Note: 'proto' not 'transport'
     *       'payload' => '96 98 97',
     *       'connection' => [...],  // Media-level connection (optional)
     *       'attributes' => [
     *         'recvonly' => null,  // Flag attribute (value is NULL)
     *         'rtpmap' => '96 PS/90000',  // Value attribute
     *         'fmtp' => '96 profile-level-id=42e01f',
     *         'setup' => 'passive'
     *       ]
     *     ]
     *   ],
     *   'gb28181' => [  // GB28181 extensions (if present)
     *     'ssrc' => '0100000001',  // y= field
     *     'f' => ''  // f= field
     *   ]
     * ]
     * ```
     * 
     * @example
     * ```php
     * // Parse GB28181 device SDP
     * $sdpBody = "v=0\r\n"
     *     . "o=34020000001320000001 0 0 IN IP4 192.168.1.100\r\n"
     *     . "s=Play\r\n"
     *     . "c=IN IP4 192.168.1.100\r\n"
     *     . "t=0 0\r\n"
     *     . "m=video 6000 RTP/AVP 96 98 97\r\n"
     *     . "a=recvonly\r\n"
     *     . "a=rtpmap:96 PS/90000\r\n"
     *     . "y=0100000001\r\n"
     *     . "f=\r\n";
     * 
     * $sdp = ExoSip::parseSdp($sdpBody);
     * 
     * if ($sdp) {
     *     // Standard fields
     *     $deviceIp = $sdp['connection']['addr'];
     *     $devicePort = $sdp['medias'][0]['port'];
     *     $protocol = $sdp['medias'][0]['proto'];
     *     
     *     // GB28181 SSRC (critical for stream identification!)
     *     $ssrc = $sdp['gb28181']['ssrc'] ?? null;
     *     
     *     // Notify media server
     *     notifyZLM($deviceIp, $devicePort, $ssrc);
     * }
     * ```
     * 
     * @example
     * ```php
     * // Handle multi-media stream (video + audio)
     * $sdp = ExoSip::parseSdp($multiMediaSdp);
     * 
     * foreach ($sdp['medias'] as $media) {
     *     if ($media['media'] === 'video') {
     *         echo "Video: {$media['port']}\n";
     *     } elseif ($media['media'] === 'audio') {
     *         echo "Audio: {$media['port']}\n";
     *     }
     * }
     * ```
     * 
     * @see SipEvent::getSdp() Recommended for SipEvent context
     */
    public static function parseSdp(string $sdp): ?array {}
    
    /**
     * Get run status from PID file (external call, static method)
     * 
     * @param string $pidFile Path to PID file (e.g., '/tmp/gb28181_server.pid')
     * @return array Process status information:
     *   - master: ['pid' => int, 'status' => string, 'memory_rss_kb' => int, 'memory_vsz_kb' => int, 'fd_count' => int]
     *   - worker: ['pid' => int, 'status' => string, 'memory_rss_kb' => int, 'memory_vsz_kb' => int, 'fd_count' => int, 'uptime' => int, 'restart_count' => int]
     *   - tasks: [['id' => int, 'pid' => int, 'status' => string, 'memory_rss_kb' => int, 'memory_vsz_kb' => int, 'fd_count' => int], ...]
     *   - error: string (if failed)
     * 
     * @example
     * ```php
     * // From external script (e.g., monitoring tool)
     * $status = ExoSip::getRunStatus('/tmp/gb28181_server.pid');
     * 
     * if (isset($status['error'])) {
     *     echo "Error: {$status['error']}\n";
     *     exit(1);
     * }
     * 
     * echo "Master PID: {$status['master']['pid']}\n";
     * echo "Master Memory: " . round($status['master']['memory_rss_kb'] / 1024, 2) . " MB\n";
     * echo "Worker PID: {$status['worker']['pid']}\n";
     * echo "Worker Memory: " . round($status['worker']['memory_rss_kb'] / 1024, 2) . " MB\n";
     * echo "Worker FD Count: {$status['worker']['fd_count']}\n";
     * echo "Worker Uptime: {$status['worker']['uptime']} seconds\n";
     * 
     * foreach ($status['tasks'] as $task) {
     *     $mem = round($task['memory_rss_kb'] / 1024, 2);
     *     echo "Task-{$task['id']}: PID {$task['pid']}, Memory {$mem} MB\n";
     * }
     * ```
     */
    public static function getRunStatus(string $pidFile): array {}
}

/**
 * SIP Event Object
 * 
 * Represents a SIP event (REGISTER, MESSAGE, INVITE, etc.)
 */
class SipEvent {
    
    /**
     * Get event type (internal numeric type)
     * 
     * @return int Event type constant
     */
    public function getType(): int {}
    
    /**
     * Get response code
     * 
     * @return int Response code (0 for requests, 200-699 for responses)
     */
    public function getCode(): int {}
    
    /**
     * Get Call-ID from event
     * 
     * Directly retrieves the eXosip call_id from the event structure.
     * This is more efficient than getSession()->getCallId() and works
     * even when no session object is available.
     * 
     * @return int eXosip call_id (cid from eXosip_event_t)
     * 
     * @example
     * ```php
     * // In response handler (e.g., INVITE 200 OK)
     * public function handleResponse(SipEvent $event): void {
     *     $callId = $event->getCallId();    // Direct access
     *     $dialogId = $event->getDialogId();
     *     
     *     // Send ACK
     *     if ($dialogId > 0) {
     *         $this->sipServer->sendAck($dialogId);
     *     }
     * }
     * ```
     */
    public function getCallId(): int {}
    
    /**
     * Get Dialog-ID from event
     * 
     * Directly retrieves the eXosip dialog_id from the event structure.
     * Used for session operations like sendAck(), sendBye().
     * 
     * @return int eXosip dialog_id (did from eXosip_event_t)
     * 
     * @example
     * ```php
     * // In INVITE 200 OK handler
     * $dialogId = $event->getDialogId();
     * if ($dialogId > 0) {
     *     $this->sipServer->sendAck($dialogId);
     *     
     *     // Later, to close the session:
     *     $this->sipServer->sendBye($dialogId);
     * }
     * ```
     */
    public function getDialogId(): int {}
    
    /**
     * Get From URI
     * 
     * @return string|null From header URI (e.g., 'sip:34020000001320000001@3402000000')
     */
    public function getFromUri(): ?string {}
    
    /**
     * Get To URI
     * 
     * @return string|null To header URI
     */
    public function getToUri(): ?string {}
    
    /**
     * Get Request-URI
     * 
     * @return string|null Request-URI
     */
    public function getRequestUri(): ?string {}
    
    /**
     * Get message body from CURRENT event
     * 
     *  IMPORTANT: This returns the body of the CURRENT event message only.
     * The body is volatile and only exists during the event callback.
     * 
     * ## Difference from SipSession::getRawBody()
     * 
     * | Method | Scope | Persistence | Use Case |
     * |--------|-------|-------------|----------|
     * | **SipEvent::getBody()** | Current event only | Volatile | Get body from the message that triggered this event |
     * | **SipSession::getRawBody()** | Cross-event | Persistent | Access earlier message body in later events |
     * 
     * ## When to Use getBody()
     * 
     *  **Use getBody() for:**
     * - Processing MESSAGE content (e.g., GB28181 XML commands)
     * - Extracting SDP from INVITE requests
     * - Reading body from current event in normal flow
     * - Most common scenarios where you handle the message immediately
     * 
     *  **Don't use getBody() when:**
     * - You need to access INVITE body in a 200 OK response handler
     * - You need cross-event body access (use getRawBody() instead)
     * 
     * ## Technical Details
     * 
     * - **C Layer**: Returns `php_sip_event_obj->body` pointer from current message
     * - **Source**: `osip_message_get_body(evt->request/response, 0, &body)`
     * - **Lifecycle**: Exists only during event callback, freed after
     * - **Thread-safe**: Yes (each event has its own body)
     * 
     * ## Examples
     * 
     * ### Example 1: Handle GB28181 MESSAGE (Recommended)
     * ```php
     * public function onMessage(SipEvent $event): void {
     *     $body = $event->getBody();  //  Get current MESSAGE body
     *     if ($body) {
     *         $xml = simplexml_load_string($body);
     *         $cmdType = (string)$xml->CmdType;
     *         
     *         if ($cmdType === 'Catalog') {
     *             $this->handleDeviceCatalog($event, $xml);
     *         }
     *     }
     * }
     * ```
     * 
     * ### Example 2: Extract SDP from INVITE
     * ```php
     * public function onInvite(SipEvent $event): void {
     *     $body = $event->getBody();  //  Get INVITE SDP body
     *     $sdp = $this->server->parseSdp($body);
     *     
     *     $session = $event->getSession();
     *     $session->getId();  // Save session for later use
     *     
     *     // Note: eXosip2 auto-sends ACK for 200 OK, no need to call sendAck()
     * }
     * ```
     * 
     * ### Example 3: Wrong Usage - Accessing INVITE Body in Response
     * ```php
     * public function handleInviteResponse(SipEvent $event): void {
     *     $body = $event->getBody();  //  WRONG! This is the 200 OK body, not INVITE
     *     
     *     // If you need INVITE SDP here, use:
     *     $session = $event->getSession();
     *     $body = $session->getRawBody();  //  Correct! Gets saved INVITE body
     * }
     * ```
     * 
     * @see SipSession::getRawBody() For persistent cross-event body access
     * @see ExoSip::parseSdp() For parsing SDP bodies
     * 
     * @return string|null Message body (e.g., XML content for GB28181, SDP for INVITE), null if no body
     */
    public function getBody(): ?string {}
    
    /**
     * Get Content-Type header
     * 
     * @return string|null Content-Type (e.g., 'Application/MANSCDP+xml')
     */
    public function getContentType(): ?string {}
    
    /**
     * Get transaction ID
     * 
     * @return int Transaction ID (use for sendResponse)
     */
    public function getTid(): int {}
    
    /**
     * Get Expires header value
     * 
     * @return int Expires value in seconds, -1 if not present, 0 for unregister
     */
    public function getExpires(): int {}
    
    /**
     * Get associated SIP session
     * 
     * IMPORTANT: Call-ID and Dialog-ID are session properties, not event properties!
     * You must first get the SipSession object, then call its methods:
     * 
     * @example
     * ```php
     * $session = $event->getSession();
     * if ($session) {
     *     $callId = $session->getCallId();     // int: eXosip call_id
     *     $fromUri = $session->getFromUri();   // string: From URI
     *     $toUri = $session->getToUri();       // string: To URI
     *     $state = $session->getState();       // string: Session state
     *     
     *     // Close session (send BYE)
     *     $session->close();
     * }
     * ```
     * 
     * @return SipSession|null Session object if exists
     */
    public function getSession(): ?SipSession {}
    
    /**
     * Get connection information
     * 
     * @return array|null Connection info array with keys:
     *   - id: int - Connection ID
     *   - device_id: string - Device ID
     *   - ip: string - IP address
     *   - port: int - Port number
     *   - state: int - Connection state
     *   - state_name: string - State name (IDLE, REGISTERED, etc.)
     *   - contact_uri: string - Contact URI
     *   - user_agent: string - User-Agent header
     *   - created_at: int - Creation timestamp
     *   - last_seen: int - Last activity timestamp
     *   - register_count: int - Registration count
     *   - message_count: int - Message count
     */
    public function getConnection(): ?array {}
    
    /**
     * Get SIP header value
     * 
     * @param string $name Header name (e.g., 'Authorization', 'WWW-Authenticate', 'Via')
     * @return string|null Header value, or null if not found
     * 
     * @example
     * ```php
     * $auth = $event->getHeader('Authorization');
     * $wwwAuth = $event->getHeader('WWW-Authenticate');
     * $via = $event->getHeader('Via');  // For received parameter
     * ```
     */
    public function getHeader(string $name): ?string {}
    
    /**
     * Parse SDP from event body (instance method - recommended for SipEvent)
     * 
     * Convenience wrapper around ExoSip::parseSdp() for SipEvent context.
     * Automatically validates Content-Type and extracts body.
     * 
     * @return array|null Parsed SDP structure, or null if:
     *   - Content-Type is not 'application/sdp'
     *   - Body is empty or invalid
     *   - SDP parsing fails
     * 
     * @example
     * ```php
     * // In INVITE 200 OK handler
     * $sip->onResponse = function($event) use ($sip) {
     *     if ($event->getCode() == 200) {
     *         //  Recommended: Use instance method
     *         $sdp = $event->getSdp();
     *         
     *         if ($sdp) {
     *             $deviceIp = $sdp['connection']['addr'];
     *             $devicePort = $sdp['medias'][0]['port'];
     *             $ssrc = $sdp['gb28181']['ssrc'] ?? null;
     *             
     *             // Notify ZLMediaKit
     *             notifyStreamServer($deviceIp, $devicePort, $ssrc);
     *             
     *             // Send ACK
     *             $sip->sendAck($event->getDialogId());
     *         }
     *     }
     * };
     * ```
     * 
     * @example
     * ```php
     * // In INVITE handler (device initiated voice broadcast)
     * $sip->onInvite = function($event) use ($sip) {
     *     $sdp = $event->getSdp();
     *     
     *     if ($sdp) {
     *         // Device audio receiving address
     *         $deviceIp = $sdp['connection']['addr'];
     *         $devicePort = $sdp['medias'][0]['port'];
     *         
     *         // Check media mode
     *         $attrs = $sdp['medias'][0]['attributes'];
     *         $mode = isset($attrs['sendonly']) ? 'sendonly' : 'recvonly';
     *         
     *         echo "Device listening on {$deviceIp}:{$devicePort} (mode: {$mode})\n";
     *         
     *         // Send 200 OK with server SDP
     *         $serverSdp = buildVoiceSdp($serverIp, $serverPort);
     *         $sip->sendResponse($event->getTid(), 200, 'OK', [
     *             'Content-Type' => 'application/sdp'
     *         ], $serverSdp);
     *     }
     * };
     * ```
     * 
     * @see ExoSip::parseSdp() Static method for parsing any SDP string
     */
    public function getSdp(): ?array {}
    
    /**
     * Get file descriptor (TCP mode only)
     * 
     * Returns the TCP connection file descriptor for this event.
     * Used in TCP mode for connection binding and management.
     * 
     * @return int File descriptor (>0 for TCP), 0 for UDP
     * 
     * @example
     * ```php
     * $sip->onRegister = function($event) use ($deviceManager) {
     *     $mode = $this->getConfig()['mode'] ?? 'udp';
     *     
     *     if ($mode === 'tcp' || $mode === 'tls') {
     *         $fd = $event->getFd();
     *         if ($fd > 0) {
     *             $deviceId = extractDeviceId($event);
     *             $deviceManager->bindConnection($deviceId, $fd);
     *         }
     *     }
     * };
     * ```
     */
    public function getFd(): int {}
}

/**
 * SIP Session Object
 * 
 * Represents a SIP dialog/session (call, subscription, etc.)
 * 
 * ## Overview
 * 
 * SipSession is a lightweight handle for managing SIP dialogs. It provides access to
 * session-level information and lifecycle management (e.g., closing sessions).
 * 
 * ## Design Philosophy
 * 
 * While SIP is fundamentally a session-based protocol, this implementation keeps
 * Session objects minimal. Most information you need is available directly from
 * SipEvent, making event handlers simpler and more efficient.
 * 
 * ## When to Use SipSession
 * 
 * ✅ **Use SipSession when:**
 * - You need to actively close a session (`$session->close()`)
 * - You're storing session handles for later operations
 * - You need cross-event session state management
 * 
 * ❌ **Don't use SipSession when:**
 * - You just need call_id or dialog_id → Use `$event->getCallId()` / `$event->getDialogId()`
 * - You just need URIs → Use `$event->getFromUri()` / `$event->getToUri()`
 * - You're processing events synchronously → Event provides everything you need
 * 
 * ## Limitations
 * 
 * ⚠️ **Important limitations:**
 * 
 * 1. **No persistent message body**: 
 *    - `getRawBody()` returns the last saved body, but it's NOT guaranteed to be the INVITE body
 *    - For INVITE SDP access in response handlers, parse it in the INVITE handler directly
 * 
 * 2. **Minimal state tracking**:
 *    - Session doesn't track dialog state (confirmed, early, terminated)
 *    - No automatic cleanup on BYE/timeout
 * 
 * 3. **Not required for most operations**:
 *    - `sendBye()` can use call_id/dialog_id directly from event
 *    - `sendAck()` uses dialog_id from event, not session
 * 
 * ## Recommended Patterns
 * 
 * ### ✅ Recommended: Direct event access (simpler)
 * ```php
 * // Modern approach - direct from event
 * public function handleInviteResponse(SipEvent $event): void {
 *     $callId = $event->getCallId();       // Direct access
 *     $dialogId = $event->getDialogId();   // Direct access
 *     
 *     // Parse SDP from current event
 *     $sdp = $event->getSdp();
 *     
 *     // Send ACK
 *     if ($dialogId > 0) {
 *         $this->sipServer->sendAck($dialogId);
 *     }
 * }
 * ```
 * 
 * ### ⚠️ Legacy: Session-based (more complex, rarely needed)
 * ```php
 * // Legacy approach - via session
 * public function handleInviteResponse(SipEvent $event): void {
 *     $session = $event->getSession();
 *     if (!$session) return;
 *     
 *     $callId = $session->getCallId();
 *     $dialogId = $session->getDialogId();
 *     
 *     // ... same logic
 * }
 * ```
 * 
 * ### ✅ Valid use case: Session storage for cleanup
 * ```php
 * // Store sessions for later cleanup
 * class SessionManager {
 *     private array $activeSessions = [];
 *     
 *     public function handleInvite(SipEvent $event): void {
 *         $session = $event->getSession();
 *         if ($session) {
 *             $this->activeSessions[$session->getId()] = $session;
 *         }
 *         
 *         // Later: cleanup timeout sessions
 *         Timer::add(30, function() use ($session) {
 *             $session->close();  // Send BYE
 *             unset($this->activeSessions[$session->getId()]);
 *         }, null, false);
 *     }
 * }
 * ```
 * 
 * ## Migration Note
 * 
 * If you're upgrading from older code that uses Session heavily, consider refactoring to:
 * - Use `$event->getCallId()` / `$event->getDialogId()` for IDs
 * - Parse SDP from `$event->getSdp()` in the event handler
 * - Only keep Session objects if you need `close()` or long-term handles
 * 
 * ## Comparison with Other Frameworks
 * 
 * | Framework | Session Concept | Notes |
 * |-----------|----------------|-------|
 * | **This Extension** | Lightweight handle | Minimal, most data on Event |
 * | **Workerman** | TcpConnection | Rich object, connection-centric |
 * | **PJSIP** | pjsip_inv_session | Full dialog state machine |
 * | **FreeSWITCH** | Channel | Comprehensive call state |
 * 
 * This design prioritizes simplicity and performance for typical GB28181 use cases.
 */
class SipSession {
    
    /**
     * Get session ID (internal session identifier)
     * 
     * @return int Session ID
     */
    public function getId(): int {}
    
    /**
     * Get eXosip Call-ID (used for BYE, re-INVITE operations)
     * 
     * IMPORTANT: This is an integer (eXosip internal call_id), not the SIP Call-ID header string!
     * Use this value when calling sendBye() or other session operations.
     * 
     * @return int eXosip call_id
     */
    public function getCallId(): int {}
    
    /**
     * Get From URI
     * 
     * @return string|null From URI
     */
    public function getFromUri(): ?string {}
    
    /**
     * Get To URI
     * 
     * @return string|null To URI
     */
    public function getToUri(): ?string {}
    
    /**
     * Get session state/type
     * 
     * NOTE: Currently returns an integer (session type) from the internal SessionInfo structure.
     * This is NOT a state string like "active", "idle", etc.
     * 
     * @return int Session type (internal value)
     */
    public function getState(): int {}
    
    /**
     * Get raw message body stored in session
     * 
     * ️ IMPORTANT DIFFERENCE from SipEvent::getBody():
     * 
     * 1. **SipSession::getRawBody()** - Returns the LAST message body saved to this session
     *    - Stored in SessionInfo->raw_body (persistent across events)
     *    - Updated when new messages arrive for this session
     *    - Useful for accessing body in later events (e.g., in response handlers)
     *    - Maximum 4096 bytes (defined by SessionInfo structure)
     * 
     * 2. **SipEvent::getBody()** - Returns the CURRENT event's message body
     *    - Extracted directly from evt->request or evt->response
     *    - Only exists for the current event
     *    - Recommended for normal use cases
     * 
     * **When to use getRawBody():**
     * - In onResponse handler when you need the original INVITE body
     * - Cross-event body access (e.g., accessing INVITE SDP in ACK handler)
     * - Session-level body persistence
     * 
     * **When to use getBody():**
     * - Normal message handling (onMessage, onInvite, etc.)
     * - Current event body is sufficient
     * - Cleaner and more intuitive
     * 
     * @return string|null Stored message body (XML, SDP, etc.), or null if empty
     * 
     * @example
     * ```php
     * // Use case 1: Access INVITE body in response handler
     * $sip->onResponse = function($event) {
     *     if ($event->getCode() == 200) {
     *         $session = $event->getSession();
     *         if ($session) {
     *             // Response event doesn't have request body,
     *             // but session stored it from the INVITE
     *             $inviteBody = $session->getRawBody();
     *             if ($inviteBody) {
     *                 $sdp = ExoSip::parseSdp($inviteBody);
     *                 echo "Original INVITE had: {$sdp['connection']['addr']}\n";
     *             }
     *         }
     *     }
     * };
     * 
     * // Use case 2: Normal handling - prefer getBody()
     * $sip->onMessage = function($event) {
     *     //  Recommended: Get current message body
     *     $body = $event->getBody();
     *     
     *     //  Not recommended: Access via session
     *     $session = $event->getSession();
     *     $bodyViaSession = $session ? $session->getRawBody() : null;
     * };
     * ```
     * 
     * @see SipEvent::getBody() Get current event's message body (recommended)
     */
    public function getRawBody(): ?string {}
    
    /**
     * Close the SIP session
     * 
     * Similar to Workerman's TcpConnection::close(), gracefully terminates the session.
     * Sends BYE request if it's an active call and cleans up session resources.
     * 
     * @return bool True on success, false on failure
     * 
     * @example
     * ```php
     * // Close session after timeout (like Workerman heartbeat example)
     * $sip->onInvite = function($event) {
     *     $session = $event->getSession();
     *     
     *     // Auto-close after 30 seconds
     *     Timer::add(30, function() use ($session) {
     *         if ($session) {
     *             $session->close();
     *         }
     *     }, null, false);
     * };
     * 
     * // Manual close
     * if ($session->getState() === 'INCALL') {
     *     $session->close();  // Send BYE and cleanup
     * }
     * ```
     */
    public function close(): bool {}
}

/**
 * SIP Client Class
 * 
 * Provides SIP client functionality for connecting to SIP servers.
 * Supports REGISTER, MESSAGE, INVITE, and other SIP methods.
 * 
 * @example
 * ```php
 * $client = new ExoSipClient([
 *     'server_ip' => '127.0.0.1',
 *     'server_port' => 5060,
 *     'username' => 'device001',
 *     'password' => '123456',
 *     'realm' => '3402000000',
 *     'mode' => 'UDP'
 * ]);
 * 
 * $client->start();
 * $client->sendRegister();
 * 
 * // Send MESSAGE
 * $client->sendMessage('sip:server@domain', 'Hello!');
 * 
 * // Process events
 * $events = $client->processEvents(100);
 * 
 * $client->stop();
 * ```
 */
class ExoSipClient {
    
    /**
     * Create SIP client instance
     * 
     * @param array $config Client configuration
     * - server_ip (string, required): Server IP address
     * - server_port (int): Server port, default 5060
     * - username (string, required): SIP username/device ID
     * - password (string): SIP password for authentication
     * - realm (string): SIP realm/domain
     * - mode (string): Transport mode (UDP|TCP), default UDP
     * - local_ip (string): Local IP to bind, optional
     * - local_port (int): Local port, 0 = auto, default 0
     * - from_uri (string): Custom From URI, optional
     * - to_uri (string): Custom To URI, optional
     * - expires (int): Registration expiry seconds, default 3600
     * - debug (bool): Enable debug output, default false
     * 
     * @throws Exception If required parameters missing or init fails
     */
    public function __construct(?array $config = null) {}
    
    /**
     * Start client (bind port and event thread)
     * 
     * @return bool True on success
     */
    public function start(): bool {}
    
    /**
     * Stop client
     * 
     * @return bool True on success
     */
    public function stop(): bool {}
    
    /**
     * Send REGISTER request
     * 
     * @return int Registration ID (>= 0), or -1 on failure
     */
    public function sendRegister(): int {}
    
    /**
     * Send UNREGISTER request (expires=0)
     * 
     * @return int 0 on success, -1 on failure
     */
    public function sendUnregister(): int {}
    
    /**
     * Send MESSAGE request
     * 
     * @param string $to_uri Target URI (e.g., 'sip:user@domain')
     * @param string $body Message body
     * @param string|null $content_type Content-Type header, default 'text/plain'
     * @return int Transaction ID (>= 0), or -1 on failure
     */
    public function sendMessage(string $to_uri, string $body, ?string $content_type = null): int {}
    
    /**
     * Send INVITE request (initiate call/session)
     * 
     * @param string $to_uri Target URI
     * @param string|null $sdp SDP body (optional)
     * @return int Call ID (> 0), or -1 on failure
     */
    public function sendInvite(string $to_uri, ?string $sdp = null): int {}
    
    /**
     * Send BYE request (terminate call/session)
     * 
     * @param int $did Dialog ID
     * @param int $cid Call ID
     * @return int 0 on success, -1 on failure
     */
    public function sendBye(int $did, int $cid): int {}
    
    /**
     * Send OPTIONS request
     * 
     * @param string $to_uri Target URI
     * @return int Transaction ID (>= 0), or -1 on failure
     */
    public function sendOptions(string $to_uri): int {}
    
    /**
     * Check if client is registered
     * 
     * @return bool True if registered
     */
    public function isRegistered(): bool {}
    
    /**
     * Get client statistics
     * 
     * @return array Statistics array
     * - registered (int): 1 if registered, 0 otherwise
     * - request_count (int): Total requests sent
     * - response_count (int): Total responses received
     * - timeout_count (int): Total timeouts
     * - register_time (int): Unix timestamp of last successful registration
     */
    public function getStats(): array {}
    
    /**
     * Process events (non-blocking)
     * 
     * @param int $timeout_ms Timeout in milliseconds, default 0 (immediate return)
     * @return array Array of events
     * Each event contains:
     * - type (int): Event type constant
     * - tid (int): Transaction ID
     * - did (int): Dialog ID
     * - cid (int): Call ID
     * - rid (int): Registration ID
     * - status_code (int): Response status code (if response)
     * - reason (string): Response reason phrase (if response)
     * - method (string): Request method (if request)
     */
    public function processEvents(int $timeout_ms = 0): array {}
}

// SIP Method Event Constants (PHP Extension)
define('SIP_EVENT_REGISTER', 1);
define('SIP_EVENT_INVITE', 2);
define('SIP_EVENT_ACK', 3);
define('SIP_EVENT_BYE', 4);
define('SIP_EVENT_CANCEL', 5);
define('SIP_EVENT_MESSAGE', 6);
define('SIP_EVENT_INFO', 7);
define('SIP_EVENT_OPTIONS', 8);
define('SIP_EVENT_SUBSCRIBE', 9);
define('SIP_EVENT_NOTIFY', 10);

// eXosip2 event type constants (from eXosip2 library)
define('EXOSIP_REGISTRATION_SUCCESS', 1);
define('EXOSIP_REGISTRATION_FAILURE', 2);
define('EXOSIP_CALL_INVITE', 3);
define('EXOSIP_CALL_REINVITE', 4);
define('EXOSIP_CALL_NOANSWER', 5);
define('EXOSIP_CALL_PROCEEDING', 6);
define('EXOSIP_CALL_RINGING', 7);
define('EXOSIP_CALL_ANSWERED', 8);
define('EXOSIP_CALL_REDIRECTED', 9);
define('EXOSIP_CALL_REQUESTFAILURE', 10);
define('EXOSIP_CALL_SERVERFAILURE', 11);
define('EXOSIP_CALL_GLOBALFAILURE', 12);
define('EXOSIP_CALL_ACK', 13);
define('EXOSIP_CALL_CANCELLED', 14);
define('EXOSIP_CALL_MESSAGE_NEW', 15);
define('EXOSIP_CALL_MESSAGE_PROCEEDING', 16);
define('EXOSIP_CALL_MESSAGE_ANSWERED', 17);
define('EXOSIP_CALL_MESSAGE_REDIRECTED', 18);
define('EXOSIP_CALL_MESSAGE_REQUESTFAILURE', 19);
define('EXOSIP_CALL_MESSAGE_SERVERFAILURE', 20);
define('EXOSIP_CALL_MESSAGE_GLOBALFAILURE', 21);
define('EXOSIP_CALL_CLOSED', 22);
define('EXOSIP_CALL_RELEASED', 23);
define('EXOSIP_MESSAGE_NEW', 24);
define('EXOSIP_MESSAGE_PROCEEDING', 25);
define('EXOSIP_MESSAGE_ANSWERED', 26);
define('EXOSIP_MESSAGE_REDIRECTED', 27);
define('EXOSIP_MESSAGE_REQUESTFAILURE', 28);
define('EXOSIP_MESSAGE_SERVERFAILURE', 29);
define('EXOSIP_MESSAGE_GLOBALFAILURE', 30);
define('EXOSIP_SUBSCRIPTION_NOANSWER', 31);
define('EXOSIP_SUBSCRIPTION_PROCEEDING', 32);
define('EXOSIP_SUBSCRIPTION_ANSWERED', 33);
define('EXOSIP_SUBSCRIPTION_REDIRECTED', 34);
define('EXOSIP_SUBSCRIPTION_REQUESTFAILURE', 35);
define('EXOSIP_SUBSCRIPTION_SERVERFAILURE', 36);
define('EXOSIP_SUBSCRIPTION_GLOBALFAILURE', 37);
define('EXOSIP_SUBSCRIPTION_NOTIFY', 38);
define('EXOSIP_IN_SUBSCRIPTION_NEW', 39);
define('EXOSIP_NOTIFICATION_NOANSWER', 40);
define('EXOSIP_NOTIFICATION_PROCEEDING', 41);
define('EXOSIP_NOTIFICATION_ANSWERED', 42);
define('EXOSIP_NOTIFICATION_REDIRECTED', 43);
define('EXOSIP_NOTIFICATION_REQUESTFAILURE', 44);
define('EXOSIP_NOTIFICATION_SERVERFAILURE', 45);
define('EXOSIP_NOTIFICATION_GLOBALFAILURE', 46);
