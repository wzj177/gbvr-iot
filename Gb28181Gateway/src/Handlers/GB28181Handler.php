<?php

namespace Gb28181\GateWay\Handlers;

use \ExoSip;
use Gb28181\GateWay\Device\DeviceManager;
use Gb28181\GateWay\Handlers\LongTask\CommandSubscriber;
use Gb28181\GateWay\Transport\TransportFactory;
use Gb28181\GateWay\Message\CommandDispatcher;
use Gb28181\GateWay\Message\SdpBuilder;
use Gb28181\GateWay\Message\CommandType\DeviceControlCommand;
use Gb28181\GateWay\Message\CommandType\RecordInfoCommand;
use Gb28181\GateWay\Message\CommandType\BroadcastCommand;
use Gb28181\GateWay\Message\MessageHandler;
use Gb28181\GateWay\Message\QuerySender;
use Gb28181\GateWay\Message\CommandType\KeepaliveCommand;
use Gb28181\GateWay\Message\CommandType\CatalogCommand;
use Gb28181\GateWay\Message\CommandType\DeviceInfoCommand;
use Gb28181\GateWay\Message\CommandType\DeviceStatusCommand;
use Gb28181\GateWay\Message\CommandType\AlarmCommand;
use Gb28181\GateWay\Message\CommandType\MobilePositionCommand;
use Gb28181\GateWay\Message\CommandType\MediaStatusCommand;
use Gb28181\GateWay\Message\CommandType\SubscribeNotifyCommand;
use Gb28181\GateWay\Message\CommandType\PresetQueryCommand;
use Gb28181\GateWay\Message\CommandType\ConfigDownloadCommand;
use Gb28181\GateWay\Traits\CurlTrait;
use Gb28181\GateWay\Wrappers\CallbackWrapper;
use Gb28181\GateWay\Libs\Logger;
use Gb28181\GateWay\Libs\ClientRedis;
use Gb28181Gateway\src\Message\CommandType\DeviceSubscribeCommand;
use Gb28181Gateway\src\Message\CommandType\DeviceToServerSubscribeHandler;

/**
 * GB28181 信令网关核心处理类
 *
 * 职责说明:
 * ========
 * 1. 处理设备注册/注销/心跳 (REGISTER)
 * 2. 处理SIP MESSAGE - GB28181 XML信令交互
 * 3. 处理SIP INVITE - 视频流请求/语音对讲
 * 4. 处理SIP NOTIFY - 异步通知消息
 * 5. 分发主动命令 (通过Redis队列)
 *
 * 协议方法说明:
 * ===========
 *
 * handleRegister()
 * ---------------
 * - 场景: 设备上线注册、定期刷新注册
 * - 方向: 设备 → 平台
 * - 流程: REGISTER → 401 → REGISTER(带认证) → 200 OK
 * - 作用: 建立设备连接，分配Expires过期时间
 *
 * handleMessage()
 * --------------
 * - 场景: GB28181 XML信令交互（查询/响应/上报）
 * - 方向: 双向
 * - 消息类型:
 *   * 设备→平台: Keepalive(心跳), Catalog(目录), DeviceInfo(设备信息),
 *                Alarm(报警), MobilePosition(位置上报)
 *   * 平台→设备: Query(查询), Control(PTZ控制等)
 * - 格式: SIP MESSAGE + XML Body
 * - 特点: 同步请求-响应模式
 *
 * handleNotify()
 * -------------
 * - 场景: 异步通知消息（设备主动推送）
 * - 方向: 设备 → 平台
 * - 消息类型:
 *   * MediaStatus: GB28181-2022 媒体状态通知
 *     - SnapshotComplete: 图像抓拍完成，包含图片URL
 *     - Keepalive: 媒体流心跳，包含码率/帧率等
 * - 格式: SIP NOTIFY + XML Body
 * - 特点: 单向通知，不需要响应数据
 *
 * handleInvite()
 * -------------
 * - 场景: 会话邀请（视频/音频）
 * - 方向: 双向
 * - 类型:
 *   * 平台→设备: 请求实时视频、录像回放
 *     流程: INVITE(SDP) → 200 OK(SDP) → ACK → RTP流传输 → BYE
 *   * 设备→平台: 语音对讲请求
 *     Subject: broadcast(广播) 或 talk(对讲)
 * - 格式: SIP INVITE + SDP Body
 * - 特点: 建立媒体流会话
 *
 * handleBye()
 * ----------
 * - 场景: 结束会话（视频/音频）
 * - 方向: 双向
 * - 流程: BYE → 200 OK
 * - 作用: 释放媒体资源、关闭RTP端口
 *
 * handleResponse()
 * ---------------
 * - 场景: 处理设备对主动命令的响应
 * - 类型:
 *   * INVITE 200 OK: 包含设备SDP，提取SSRC用于ZLM更新
 *   * MESSAGE 200 OK: 查询命令已被接收，等待MESSAGE响应
 * - 作用: 确认命令执行，提取关键参数
 */
class GB28181Handler
{
    use CurlTrait;

    private ExoSip $sipServer;
    private array $config;
    private ?DeviceManager $deviceManager = null;
    private ?MessageHandler $messageHandler = null;
    private ?QuerySender $querySender = null;
    private ?CommandDispatcher $commandDispatcher = null;
    private Logger $logger;

    /**
     * 已处理过 200 OK 的 INVITE call_id 集合
     * 用于防止设备重传 200 OK 时重复处理（只需重发 ACK，不需要重走整个流程）
     * @var array<int, int> call_id => dialog_id
     */
    private array $processedInviteCallIds = [];

    /**
     * 等待 API 返回 RTP 设置结果的 INVITE 请求
     * 用于 broadcast 模式：收到设备 INVITE 后先异步调 API 开 ZLM 端口，
     * 等 Task 返回后再发 200 OK。
     * @var array<int, array> taskId => { tid, pendingBroadcast, deviceSdp }
     */
    private array $pendingInviteSetup = [];

    /**
     * 等待设备 ACK 后再触发 startSendRtp 的广播会话
     * 用于 broadcastPushAfterAck=true 模式（默认）
     * @var array<int, array> callId => { session_id, ssrc, stream_id, app, media_server_id, ... }
     */
    private array $pendingBroadcastAck = [];

    /**
     * 上次心跳上报时间戳
     * @var int
     */
    private int $lastHeartbeatSent = 0;

    /**
     * 构造函数
     * @param ExoSip $sipServer SIP服务器实例
     * @param array $config 配置参数
     */
    public function __construct(ExoSip $sipServer, array $config = [])
    {
        $this->sipServer = $sipServer;

        $this->config = array_merge([
            'heartbeat_timeout'  => 180,
            'check_interval'     => 30,
            'register_expires'   => 3600,
            'catalog_auto_query' => false,
        ], $config);

        // 初始化日志
        $this->logger = Logger::getInstance([
            'log_file'  => $config['log_file'] ?? 'php://stdout',
            'min_level' => ($config['debug'] ?? false) ? 'DEBUG' : ($config['log_level'] ?? 'INFO'),
            'max_days'  => $config['log_max_days'] ?? 30,
        ]);

        // 初始化设备管理器
        $this->deviceManager = new DeviceManager(
            $this->config['heartbeat_timeout'],
            $this->config['check_interval'],
            [
                'cache_file' => $this->config['device_cache_file'] ?? '/tmp/gb28181_devices.cache',
                'api_loader' => [$this, 'deviceManagerListener'],
            ]
        );

        // 初始化消息处理器
        $this->messageHandler = new MessageHandler();
        $this->messageHandler->registerCommand(new KeepaliveCommand());
        $this->messageHandler->registerCommand(new CatalogCommand());
        $this->messageHandler->registerCommand(new DeviceInfoCommand());
        $this->messageHandler->registerCommand(new DeviceStatusCommand());
        $this->messageHandler->registerCommand(new AlarmCommand());
        $this->messageHandler->registerCommand(new MobilePositionCommand());
        $this->messageHandler->registerCommand(new MediaStatusCommand());
        $this->messageHandler->registerCommand(new DeviceControlCommand());
        $this->messageHandler->registerCommand(new RecordInfoCommand());
        $this->messageHandler->registerCommand(new BroadcastCommand());
        $this->messageHandler->registerCommand(new PresetQueryCommand());
        $this->messageHandler->registerCommand(new ConfigDownloadCommand());

        // 初始化查询发送器
        $this->querySender = new QuerySender($sipServer, [
            'server_id'     => $this->config['server_id'],
            'server_domain' => $this->config['server_domain'],
            'debug'         => $this->config['debug'] ?? false,
        ]);

        // 初始化命令分发器
        $this->commandDispatcher = new CommandDispatcher(
            $sipServer,
            $this->querySender,
            $this->deviceManager,
            [
                'debug'     => $this->config['debug'] ?? false,
                'server_id' => $this->config['server_id'],
            ]
        );

        $this->log("GB28181 协议处理器已初始化");
    }

    /**
     * 绑定事件处理器到SIP服务器
     */
    public function bindEvents() : void
    {
        // 核心SIP方法事件
        $this->sipServer->onWorkerStart = CallbackWrapper::wrap($this, 'handleWorkerStart', $this->logger);
        // 绑定 onPipeMessage (接收Task的推送)
        $this->sipServer->onPipeMessage = CallbackWrapper::wrap($this, 'handleOnPipeMessage', $this->logger);
        $this->sipServer->onRegister = CallbackWrapper::wrap($this, 'handleRegister', $this->logger); //[$this, 'handleRegister'];
        $this->sipServer->onMessage = CallbackWrapper::wrap($this, 'handleMessage', $this->logger);//[$this, 'handleMessage'];
        $this->sipServer->onInvite = CallbackWrapper::wrap($this, 'handleInvite', $this->logger);//[$this, 'handleInvite'];
        $this->sipServer->onBye = CallbackWrapper::wrap($this, 'handleBye', $this->logger);//[$this, 'handleBye'];
        $this->sipServer->onAck = CallbackWrapper::wrap($this, 'handleAck', $this->logger);//[$this, 'handleAck'];

        // SIP扩展方法
        $this->sipServer->onInfo = CallbackWrapper::wrap($this, 'handleInfo', $this->logger);//[$this, 'handleInfo'];           // INFO消息（PTZ控制等）
        $this->sipServer->onUpdate = CallbackWrapper::wrap($this, 'handleUpdate', $this->logger);//[$this, 'handleUpdate'];       // UPDATE请求
        $this->sipServer->onRefer = CallbackWrapper::wrap($this, 'handleRefer', $this->logger);//[$this, 'handleRefer'];         // REFER转接

        // Publish-Subscribe（订阅/通知机制）
        $this->sipServer->onSubscribe = CallbackWrapper::wrap($this, 'handleSubscribe', $this->logger);//[$this, 'handleSubscribe']; // 订阅请求
        $this->sipServer->onNotify = CallbackWrapper::wrap($this, 'handleNotify', $this->logger);//[$this, 'handleNotify'];       // 通知消息

        // 响应和错误处理
        $this->sipServer->onResponse = CallbackWrapper::wrap($this, 'handleResponse', $this->logger);//[$this, 'handleResponse'];   // 响应事件
        $this->sipServer->onTimeout = CallbackWrapper::wrap($this, 'handleTimeout', $this->logger);//[$this, 'handleTimeout'];     // 超时事件
        $this->sipServer->onError = CallbackWrapper::wrap($this, 'handleError', $this->logger);// [$this, 'handleError'];         // 错误事件

        // 可选的其他事件
        // $this->sipServer->onCancel = [$this, 'handleCancel'];
        // $this->sipServer->onOptions = [$this, 'handleOptions'];
        // $this->sipServer->onPrack = [$this, 'handlePrack'];
        // $this->sipServer->onPublish = [$this, 'handlePublish'];
        //

        $this->sipServer->onTimer = CallbackWrapper::wrap($this, 'tick', $this->logger);//[$this, 'tick'];                // 底层定时器，主要用于处理设备心跳超时和离线设备清理

        $this->sipServer->onTask = CallbackWrapper::wrap($this, 'handleTask', $this->logger);//[$this, 'handleTask'];                // task接收

        $this->sipServer->onTaskFinish = CallbackWrapper::wrap($this, 'handleTaskFinish', $this->logger);//[$this, 'handleTaskFinish'];      // task执行完成
    }

    /**
     * 设备管理器监听器
     * @return array|null
     */
    public function deviceManagerListener() : ?array
    {
        // TODO: 思考：如果api删除了设备是否需要更新当前内存里面的deviceManager
        // 从hock API拉取在线设备列表
        $url = $this->config['api_pull_url'];
        $response = $this->curlGet($url);
        if (!$response || !isset($response['code']) || $response['code'] !== 0) {
            $this->log("API加载设备失败: " . ($response['message'] ?? 'Unknown error'), 'WARNING');
            return null;
        }

        $devices = $response['data'] ?? [];
        $this->log("API返回 " . count($devices) . " 个在线设备");

        return $devices;
    }

    # region SIP事件处理
    public function handleWorkerStart(ExoSip $server) : void
    {
        $this->log("Worker started (PID: " . posix_getpid() . ")");

        //  捕获需要的变量到闭包
        $config = $this->config;
        $debug = $config['debug'] ?? false;
        // Gateway 自动注册（集群模式下向 API 注册自己）
        $gatewayId = $this->config['gateway_id'] ?? null;
        if ($gatewayId) {
            $this->registerGateway($gatewayId);
        }

        // 启动命令订阅器 Long Task（2个进程，各消费不同队列）
        // lt_id=0 → priority队列（注册后触发的设备发现/订阅命令）
        // lt_id=1 → normal队列（实时视频/回放/PTZ等用户操作命令）
        $longTaskCallback = function () use ($server, $config, $debug) {
            $lid = $server->longtaskGetId();

            $transportType = $config['mq_type'] ?? 'redis';
            $this->log("[LongTask-{$lid}] Command Subscriber started (PID: " . getmypid() . "), transport={$transportType}");

            // 根据 mq_type 创建 Transport
            if ($transportType === 'redis') {
                $transportConfig = $config['redis'] ?? [];
            } else {
                $transportConfig = $config['mq_config'] ?? [];
            }

            $transport = TransportFactory::create($transportType, $transportConfig);

            // 拼接完整队列名：queue_name + ':' + 分类后缀 + ':' + gateway_id
            $baseQueueName = $config['redis']['queue_name'] ?? 'gb28181:commands';
            $gatewayId = $config['gateway_id'] ?? '';
            $suffix = $gatewayId ? ':' . $gatewayId : '';

            // LongTask 队列映射：lt_id => 队列分类后缀
            $queueMap = [
                0 => ':priority',  // 设备发现/目录/订阅等注册后续命令
                1 => ':normal',    // 实时视频/回放/PTZ等用户操作命令
            ];

            $queueSuffix = $queueMap[$lid] ?? ':normal';
            $queueKey = $baseQueueName . $queueSuffix . $suffix;

            $this->log("[LongTask-{$lid}] Consuming queue: {$queueKey}");

            $subscriber = new CommandSubscriber($transport, $debug);
            $subscriber->run($server, $queueKey, 1);
        };

        $server->startLongTask($longTaskCallback);
        $server->startLongTask($longTaskCallback);
    }

    /**
     * Worker接收Task的推送
     * @param array|null $message
     * @return void
     */
    public function handleOnPipeMessage(?array $message) : void
    {
        if (!$message) {
            $this->log("Invalid message format", 'ERROR');
            return;
        }
        $this->log("Received pipe message", 'DEBUG');

        if (!isset($message['action'])) {
            $this->log("Invalid message format: missing action", 'ERROR');
            return;
        }

        // 使用 CommandDispatcher 分发命令
        $result = $this->commandDispatcher->dispatch($message);

        if ($this->config['debug']) {
            $this->log("Command result: " . json_encode($result, JSON_UNESCAPED_UNICODE), 'DEBUG');
        }

        if (!$result['success']) {
            $msg = $result['error'] ?? 'Unknown error';
            if (isset($result['message'])) {
                $msg = $result['message'];
            }
            // Device not found 说明设备连接在另一个传输进程（UDP/TCP），重新入队让对方处理
            if (str_contains($msg, 'Device not found')) {
                $this->requeueCommand($message);
                return;
            }
            $this->log("Command failed: {$msg}", 'ERROR');
        }

        $this->curlPost($this->config['api_hock_url'], [
            'scene' => 'gateway_cmd_after',
            'body'  => $result, // 替换为你要发送的实际数据
        ]);
    }

    /**
     * 将命令重新推回队列尾部，供另一个传输进程（UDP/TCP）消费
     */
    private function requeueCommand(array $message) : void
    {
        $redisConfig = $this->config['redis'] ?? [];
        if (empty($redisConfig)) {
            $this->log("requeueCommand: no redis config, command dropped", 'ERROR');
            return;
        }

        $baseQueue = $redisConfig['queue_name'] ?? 'gb28181:commands';
        $gatewayId = $this->config['gateway_id'] ?? '';
        $suffix = $gatewayId ? ':' . $gatewayId : '';

        // 按action分类到对应队列
        $queueSuffix = $this->classifyAction($message['action'] ?? '');
        $queueKey = $baseQueue . $queueSuffix . $suffix;

        try {
            $redis = new ClientRedis($redisConfig);
            $redis->connect();
            $redis->rPush($queueKey, json_encode($message));
            $this->log("Command requeued: action={$message['action']}, device={$message['device_id']}, queue={$queueKey}", 'DEBUG');
        } catch (\Throwable $e) {
            $this->log("requeueCommand failed: " . $e->getMessage(), 'ERROR');
        }
    }

    /**
     * 根据action类型返回队列分类后缀
     */
    public static function classifyAction(string $action) : string
    {
        return match ($action) {
            'query_catalog', 'query_device_info', 'query_device_status',
            'device_update', 'subscribe_catalog', 'subscribe_alarm',
            'subscribe_mobile_position', 'unsubscribe_catalog',
            'unsubscribe_alarm', 'unsubscribe_mobile_position',
            'refresh_subscribe' => ':priority',
            default => ':normal',
        };
    }

    /**
     * 定时任务处理（在主循环中调用）
     */
    public function tick() : void
    {
        static $lastCheckTime = 0;
        static $lastCleanupTime = 0;

        $now = time();

        // 检查设备心跳超时
        //        $this->log("Checking device heartbeat timeout:{$lastCheckTime}-{$this->config['check_interval']}");
        if ($now - $lastCheckTime >= $this->config['check_interval']) {
            $timeoutDevices = $this->deviceManager->checkTimeout();
            $lastCheckTime = $now;

            // 清理超时的待处理广播会话（30秒无设备 INVITE 响应则过期）
            $this->commandDispatcher->cleanExpiredBroadcasts(30);

            // 通知 API 更新超时设备状态为 expired
            if (!empty($timeoutDevices)) {
                $this->log("Checking device heartbeat timeout:{$lastCheckTime}");
                $this->log("发现 " . count($timeoutDevices) . " 个心跳超时设备", 'WARNING');
                foreach ($timeoutDevices as $device) {
                    //                    $device = $this->deviceManager->getDevice($deviceId);
                    $this->postTask('device_expired', [
                        'device_id'      => $device['device_id'],
                        'last_heartbeat' => $device['last_heartbeat'] ?? 0,
                        'timeout'        => $this->config['heartbeat_timeout'],
                        'timestamp'      => $now,
                    ]);
                    $this->log("设备心跳超时: {$device['device_id']}", 'WARNING');
                }
            }
        }

        // TODO: 清理离线设备
        $cleanupInterval = $this->config['check_offline_device_interval'] ?? 3600;
        if ($now - $lastCleanupTime >= $cleanupInterval) {
            $this->deviceManager->cleanupOfflineDevices();

            // 清理过期的 processedInviteCallIds（防止内存泄漏）
            // 通过与 CommandDispatcher 的 activeSessions 对比，移除已不存在的 call_id
            $activeSessions = $this->commandDispatcher->getActiveSessions();
            $activeCallIds = [];
            foreach ($activeSessions as $session) {
                $activeCallIds[$session['call_id']] = true;
            }
            foreach ($this->processedInviteCallIds as $callId => $dialogId) {
                if (!isset($activeCallIds[$callId])) {
                    unset($this->processedInviteCallIds[$callId]);
                }
            }
            //            $offlineDevices = $this->deviceManager->cleanupOfflineDevices();
            //            $lastCleanupTime = $now;

            //  TODO：这里不需要了，通知 API 更新离线设备状态为 offline
            //            if (!empty($offlineDevices)) {
            //                $this->log("清理 " . count($offlineDevices) . " 个离线设备");
            //                foreach ($offlineDevices as $deviceId => $device) {
            //                    $this->postTask('device_offline', [
            //                        'device_id' => $deviceId,
            //                        'registered_at' => $device['registered_at'] ?? 0,
            //                        'last_heartbeat' => $device['last_heartbeat'] ?? 0,
            //                        'timestamp' => $now,
            //                    ]);
            //                    $this->log("设备已离线: {$deviceId}");
            //                }
            //            } else {
            //                $this->log("无离线设备需要清理");
            //            }
            //        }
        }

        // Gateway 心跳上报（每30秒）
        $gatewayId = $this->config['gateway_id'] ?? null;
        if ($gatewayId && ($now - $this->lastHeartbeatSent >= 30)) {
            $this->sendGatewayHeartbeat();
            $this->lastHeartbeatSent = $now;
        }
    }

    /**
     * 向 API 上报 Gateway 心跳
     * POST /api/v2/gb/gateway/heartbeat
     */
    private function sendGatewayHeartbeat() : void
    {
        $gatewayId = $this->config['gateway_id'] ?? null;
        if (empty($gatewayId)) {
            return;
        }

        $heartbeatUrl = preg_replace('#/server/hook$#', '/gateway/heartbeat', $this->config['api_hock_url']);
        $transport = 'UDP';
        if (isset($this->config['transport'])) {
            $transport = $this->config['transport'];
        } else if (isset($this->config['mode'])) {
            $transport = $this->config['mode'];
        }

        $payload = [
            'gateway_id'   => $gatewayId,
            'pid'          => getmypid(),
            'ip'           => gethostbyname(gethostname()),
            'device_count' => count($this->deviceManager->getOnlineDevices()),
            'transport'    => $transport,
        ];

        try {
            $this->curlPost($heartbeatUrl, $payload);
            $this->log("Gateway heartbeat sent: gateway_id={$gatewayId}, device_count={$payload['device_count']}", 'DEBUG');
        } catch (\Throwable $e) {
            $this->log("Gateway heartbeat failed: {$e->getMessage()}", 'ERROR');
        }
    }

    /**
     * 向 API 注册 Gateway（自动注册，防重复写入）
     * POST /api/v2/gb/gateway/register
     */
    private function registerGateway(string $gatewayId) : void
    {
        $registerUrl = preg_replace('#/server/hook$#', '/gateway/register', $this->config['api_hock_url']);

        $cfg = $this->config;

        // 构建 redis_config（去掉 queue_name，由 API 端根据 gateway_id 生成）
        $redisConfig = $cfg['redis'] ?? [];
        unset($redisConfig['queue_name']);

        // api_config：从展开的别名 key 重新组装为嵌套格式
        $apiConfig = [
            'hock_url' => $cfg['api_hock_url'] ?? '',
            'pull_url' => $cfg['api_pull_url'] ?? '',
            'token'    => $cfg['api_hock_token'] ?? '',
        ];

        $payload = [
            'gateway_id'               => $gatewayId,
            'gateway_name'             => $cfg['gateway_name'] ?? ('Gateway-' . $gatewayId),
            'server_id'                => $cfg['server_id'] ?? '',
            'server_domain'            => $cfg['server_domain'] ?? '',
            'sip_host'                 => $cfg['sip_host'] ?? $cfg['listen_addr'] ?? '0.0.0.0',
            'sip_port'                 => $cfg['sip_port'] ?? 5060,
            'transport'                => $cfg['transport'] ?? 'UDP',
            'public_ip'                => $cfg['public_ip'] ?? '',
            'device_password'          => $cfg['device_password'] ?? '',
            'authentication'           => $cfg['authentication'] ?? true,
            'sip_username'             => $cfg['sip_username'] ?? '',
            'register_expires'         => $cfg['register_expires'] ?? 3600,
            'keepalive_interval'       => $cfg['keepalive_interval'] ?? 60,
            'heartbeat_timeout'        => $cfg['heartbeat_timeout'] ?? 180,
            'keepalive_lost_number'    => $cfg['keepalive_lost_number'] ?? 3,
            'catalog_auto_query'       => $cfg['catalog_auto_query'] ?? true,
            'encoding_type'            => $cfg['encoding_type'] ?? 'GB2312',
            'task_worker_num'          => $cfg['task_worker_num'] ?? 4,
            'timer_interval'           => $cfg['timer_interval'] ?? $cfg['check_interval'] ?? 60,
            'max_devices'              => $cfg['max_devices'] ?? 10000,
            'broadcast_push_after_ack' => $cfg['broadcast_push_after_ack'] ?? true,
            'mq_type'                  => $cfg['mq_type'] ?? 'redis',
            'mq_config'                => $cfg['mq_config'] ?? [],
            'redis_config'             => $redisConfig,
            'api_config'               => $apiConfig,
            'log_level'                => $cfg['log_level'] ?? 'INFO',
            'debug'                    => $cfg['debug'] ?? false,
            'pid'                      => getmypid(),
            'ip'                       => gethostbyname(gethostname()),
        ];

        try {
            $response = $this->curlPost($registerUrl, $payload);
            $this->log("Gateway registered successfully: gateway_id={$gatewayId}, response={$response}", 'INFO');
        } catch (\Throwable $e) {
            $this->log("Gateway registration failed: {$e->getMessage()}", 'WARNING');
        }
    }

    /**
     * task 进程：任务处理，收到AddTask
     * @param $taskId
     * @param $taskData
     */
    public function handleTask($taskId, $taskData) : array
    {
        $this->log("Task #{$taskId} processing", 'DEBUG');
        if (empty($taskData)) {
            return [
                'success' => false,
                'error'   => 'Invalid task data',
            ];
        }

        // 检查 action 类型，区分 CommandDispatcher 的 api_callback 和普通任务
        $action = $taskData['action'] ?? '';

        if ($action === 'broadcast_setup_rtp') {
            // === 广播 RTP 设置：需要解析 API 返回值 ===
            $payload = $taskData['payload'] ?? [];
            $apiUrl = !empty($taskData['api_hook_url'])
                ? $taskData['api_hook_url']
                : $this->config['api_hock_url'];

            $this->log("Task #{$taskId} broadcast_setup_rtp: url={$apiUrl}", 'DEBUG');

            $response = $this->curlPost($apiUrl, [
                'scene' => 'broadcast_setup_rtp',
                'body'  => $payload,
            ]);

            // 解析 API JSON 响应
            $apiResult = null;
            if ($response && is_string($response)) {
                $decoded = json_decode($response, true);
                if ($decoded && isset($decoded['code']) && $decoded['code'] == 0 && isset($decoded['data'])) {
                    $apiResult = $decoded['data'];
                } else {
                    $this->log("Task #{$taskId} broadcast_setup_rtp API 返回异常: " . substr((string)$response, 0, 500), 'ERROR');
                }
            }

            return [
                'success'    => $apiResult !== null,
                'task_id'    => $taskId,
                'action'     => 'broadcast_setup_rtp',
                'api_result' => $apiResult,
            ];
        } else if ($action === 'start_send_rtp') {
            // === 广播 startSendRtp：ACK 后或立即推流 ===
            $payload = $taskData['payload'] ?? [];
            $apiUrl = !empty($taskData['api_hook_url'])
                ? $taskData['api_hook_url']
                : $this->config['api_hock_url'];

            $this->log("Task #{$taskId} start_send_rtp: sessionId={$payload['session_id']}, url={$apiUrl}", 'DEBUG');

            $response = $this->curlPost($apiUrl, [
                'scene' => 'start_send_rtp',
                'body'  => $payload,
            ]);

            // 解析 API JSON 响应
            $apiResult = null;
            if ($response && is_string($response)) {
                $decoded = json_decode($response, true);
                if ($decoded && isset($decoded['code']) && $decoded['code'] == 0) {
                    $apiResult = $decoded['data'] ?? [];
                } else {
                    $this->log("Task #{$taskId} start_send_rtp API 返回异常: " . substr((string)$response, 0, 500), 'ERROR');
                }
            }

            return [
                'success'    => $apiResult !== null,
                'task_id'    => $taskId,
                'action'     => 'start_send_rtp',
                'api_result' => $apiResult,
            ];
        } else if ($action === 'api_callback') {
            // 来自 CommandDispatcher 的 API 回调任务
            $type = $taskData['type'] ?? 'unknown';
            $payload = $taskData['payload'] ?? [];

            // 使用 payload 中的 api_hook_url 或默认配置
            $apiUrl = !empty($taskData['api_hook_url'])
                ? $taskData['api_hook_url']
                : $this->config['api_hock_url'];

            $this->log("Task #{$taskId} api_callback: type={$type}, url={$apiUrl}", 'DEBUG');

            $this->curlPost($apiUrl, [
                'scene' => $type,
                'body'  => $payload,
            ]);
        } else {
            // 普通任务（兼容旧格式）
            $type = $taskData['type'] ?? 'unknown';
            $this->curlPost($this->config['api_hock_url'], [
                'scene' => $type,
                'body'  => $taskData['payload'] ?? [],
            ]);
        }

        return [
            'success' => true,
            'task_id' => $taskId,
        ];
    }


    /**
     * 任务完成处理
     * @param $taskId
     * @param $result
     * @return void
     */
    public function handleTaskFinish($taskId, $result) : void
    {
//        $this->log("Task #{$taskId} finished", 'DEBUG');

        $action = $result['action'] ?? '';

        // 根据 action 类型分发处理
        if ($action === 'broadcast_setup_rtp' && isset($this->pendingInviteSetup[$taskId])) {
            $this->handleBroadcastSetupRtpResult($taskId, $result);
            return;
        }

        // 兜底：pendingInviteSetup 中有记录但 action 未匹配（兼容旧格式）
        if (isset($this->pendingInviteSetup[$taskId])) {
            $this->log("Task #{$taskId} pendingInviteSetup hit without action match, action={$action}", 'WARNING');
            $this->handleBroadcastSetupRtpResult($taskId, $result);
            return;
        }

        if (isset($result['success']) && !$result['success']) {
            $this->log("Task #{$taskId} failed: " . ($result['error'] ?? 'unknown'), 'ERROR');
        }
    }

    /**
     * 处理 broadcast_setup_rtp Task 返回结果（WVP 对齐）
     *
     * 第五步：判断流是否已就绪
     * - 流存在 -> sendOk（200 OK + SDP）
     * - 流不存在 -> 410 Gone + stopAudioBroadcast 清理
     *
     * 第六步：sendOk - 回复 200 OK
     * - 构建 SDP 响应（a=sendonly，端口为 ZLM 发流本地端口，y=ssrc）
     * - 回复 200 OK 给设备
     * - 更新 pendingBroadcasts -> activeSessions
     *
     * 第七步：等待 ACK 判断
     * - broadcastPushAfterAck=true（默认）: 将会话记入 pendingBroadcastAck，等 ACK 再推流
     * - broadcastPushAfterAck=false 或 TCP 主动模式: 立即投递 startSendRtp 任务
     */
    private function handleBroadcastSetupRtpResult(int $taskId, array $result) : void
    {
        $setup = $this->pendingInviteSetup[$taskId];
        unset($this->pendingInviteSetup[$taskId]);

        $tid = $setup['tid'];
        $callId = $setup['call_id'];
        $dialogId = $setup['dialog_id'];
        $pendingBroadcast = $setup['pending_broadcast'];
        $broadcastKey = $setup['broadcast_key'];
        $deviceTransport = $setup['device_transport'];
        $deviceSetup = $setup['device_setup'];
        $deviceIp = $setup['device_ip'] ?? null;
        $devicePort = $setup['device_port'] ?? null;

        $channelId = $pendingBroadcast['channel_id'];
        $deviceId = $pendingBroadcast['device_id'];
        $ssrc = $pendingBroadcast['ssrc'];
        $rtpPort = $pendingBroadcast['rtp_port'];
        $mediaServerIp = $pendingBroadcast['media_server_ip'];
        $streamId = $pendingBroadcast['stream_id'];
        $sessionId = $pendingBroadcast['session_id'] ?? null;

        // 检查 API 是否成功
        $apiResult = $result['api_result'] ?? null;
        if (!$apiResult || empty($result['success'])) {
            $this->log("广播 broadcast_setup_rtp 失败，发送 500: taskId={$taskId}", 'ERROR');
            $this->sipServer->sendCallAnswer($tid, 500, null, 'Internal Server Error');
            $this->commandDispatcher->removePendingBroadcast($broadcastKey);
            return;
        }

        // === 第五步（WVP 对齐）：判断流是否已就绪 ===
        $streamReady = $apiResult['stream_ready'] ?? true; // 向后兼容：如果 API 未返回此字段，默认就绪
        if (!$streamReady) {
            // 流已不存在（前端停止推流），回复 410 Gone
            $this->log("广播流已不存在，回复 410 Gone: sessionId={$sessionId}, streamId={$streamId}", 'WARNING');
            $this->sipServer->sendCallAnswer($tid, 410, null, 'Gone - Stream not available');
            $this->commandDispatcher->removePendingBroadcast($broadcastKey);

            // 投递 stopAudioBroadcast 清理任务
            $this->postTask('broadcast_stop', [
                'session_id' => $sessionId,
                'device_id'  => $deviceId,
                'channel_id' => $channelId,
                'reason'     => 'stream_gone_on_invite',
                'timestamp'  => time(),
            ]);
            return;
        }

        // === 第六步（WVP 对齐）：回复 200 OK + SDP ===
        // 从 API 结果获取实际的 ZLM 端口和 TCP 模式
        $localPort = $apiResult['local_port'] ?? $rtpPort;
        $tcpMode = $apiResult['tcp_mode'] ?? 0;
        $actualMediaServerIp = $apiResult['media_server_ip'] ?? $mediaServerIp;
        $actualSsrc = $apiResult['ssrc'] ?? $ssrc;

        $this->log("广播 broadcast_setup_rtp 成功: localPort={$localPort}, tcpMode={$tcpMode}, ssrc={$actualSsrc}, streamReady={$streamReady}");

        // 构建服务器 SDP（Broadcast + sendonly，与 WVP 一致）
        $serverSdp = SdpBuilder::buildBroadcastSdp(
            serverId: $this->config['server_id'],
            mediaIp: $actualMediaServerIp,
            mediaPort: $localPort,
            ssrc: $actualSsrc,
            tcpMode: $tcpMode,
            mode: 'sendonly',
        );

        if ($this->config['debug'] ?? false) {
            $this->log("广播 200 OK SDP:\n{$serverSdp}");
        }

        // 发送 200 OK 带 SDP
        $sendResult = $this->sipServer->sendCallAnswer(
            $tid,
            200,
            $serverSdp,
            'OK'
        );

        if ($sendResult === false) {
            $this->log("广播 200 OK 发送失败", 'ERROR');
            $this->commandDispatcher->removePendingBroadcast($broadcastKey);
            return;
        }

        $this->log("广播 200 OK 已发送: channelId={$channelId}, Call-ID={$callId}, Dialog-ID={$dialogId}");

        // 从 pendingBroadcasts 移除，添加到 activeSessions
        $this->commandDispatcher->removePendingBroadcast($broadcastKey);
        $this->commandDispatcher->addActiveSession($streamId, [
            'request_id' => $pendingBroadcast['request_id'] ?? uniqid(),
            'call_id'    => $callId,
            'dialog_id'  => $dialogId,
            'device_id'  => $deviceId,
            'channel_id' => $channelId,
            'type'       => 'broadcast',
            'ssrc'       => $actualSsrc,
            'rtp_port'   => $localPort,
            'stream_id'  => $streamId,
            'mode'       => 'sendonly',
            'tcp_mode'   => $tcpMode,
            'session_id' => $sessionId,
            'started_at' => time(),
        ]);

        // 通知 API 会话已建立
        $this->postTask('voice_established', [
            'device_id'  => $deviceId,
            'channel_id' => $channelId,
            'dialog_id'  => $dialogId,
            'call_id'    => $callId,
            'mode'       => 'broadcast',
            'ssrc'       => $actualSsrc,
            'stream_id'  => $streamId,
            'session_id' => $sessionId,
            'timestamp'  => time(),
        ]);

        // === 第七步（WVP 对齐）：判断是否等 ACK 再推流 ===
        // broadcastPushAfterAck: 默认 true，大华等设备需要收到 ACK 后才能接收 RTP
        $broadcastPushAfterAck = $this->config['broadcast_push_after_ack'] ?? true;
        $isTcpActive = ($tcpMode == 2); // TCP 主动模式不等 ACK

        if ($broadcastPushAfterAck && !$isTcpActive) {
            // 等待设备 ACK 再推流，存入 pendingBroadcastAck
            $this->pendingBroadcastAck[$callId] = [
                'session_id'      => $sessionId,
                'device_id'       => $deviceId,
                'channel_id'      => $channelId,
                'ssrc'            => $actualSsrc,
                'stream_id'       => $streamId,
                'app'             => $pendingBroadcast['app'] ?? 'broadcast',
                'media_server_id' => $pendingBroadcast['media_server_id'] ?? '',
                'local_port'      => $localPort,
                'tcp_mode'        => $tcpMode,
                'device_ip'       => $deviceIp,
                'device_port'     => $devicePort,
            ];
            $this->log("广播: 等待设备 ACK 后再推流 (broadcastPushAfterAck=true), callId={$callId}");
        } else {
            // 立即推流（broadcastPushAfterAck=false 或 TCP 主动模式）
            $this->log("广播: 立即投递 startSendRtp 任务 (broadcastPushAfterAck=false 或 TCP主动), callId={$callId}");
            $this->dispatchStartSendRtp([
                'session_id'      => $sessionId,
                'device_id'       => $deviceId,
                'channel_id'      => $channelId,
                'ssrc'            => $actualSsrc,
                'stream_id'       => $streamId,
                'app'             => $pendingBroadcast['app'] ?? 'broadcast',
                'media_server_id' => $pendingBroadcast['media_server_id'] ?? '',
                'local_port'      => $localPort,
                'tcp_mode'        => $tcpMode,
                'device_ip'       => $deviceIp,
                'device_port'     => $devicePort,
            ]);
        }

        $this->log("广播会话已建立: {$deviceId}/{$channelId}, Stream: {$streamId}");
    }

    /**
     * 处理设备注册（包括注销）
     */
    public function handleRegister(\SipEvent $event) : void
    {
        $fromUri = $event->getFromUri();
        $deviceId = $this->extractDeviceId($fromUri);

        // 验证设备ID格式（20位数字）
        if (!$this->isValidDeviceId($deviceId)) {
            $this->log("无效的设备ID格式: {$deviceId}", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 400, 'Bad Request');
            return;
        }

        // 检查是否为注销请求（Expires: 0）
        if ($this->isUnregisterRequest($event)) {
            $this->log("设备注销: {$deviceId}");

            // 获取设备信息用于通知
            $device = $this->deviceManager->getDevice($deviceId);

            // 从内存移除设备
            $this->deviceManager->removeDevice($deviceId);

            // 发送响应
            $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
                'Expires' => 0,
            ]);

            // 通知 API 更新状态为 unregistered
            $this->postTask('device_unregister', [
                'device_id'      => $deviceId,
                'registered_at'  => $device['registered_at'] ?? 0,
                'last_heartbeat' => $device['last_heartbeat'] ?? 0,
                'expires'        => 0,
                'timestamp'      => time(),
            ]);

            $stats = $this->deviceManager->getStats();
            $this->log("设备已注销，当前在线设备: {$stats['online']}");
            return;
        }

        // 检查是否包含 Authorization 头
        // 第一次注册（无 Authorization）-> 返回 401
        // 第二次注册（有 Authorization）-> 验证后返回 200
        $hasAuth = $this->hasAuthorizationHeader($event);

        if (!$hasAuth) {
            // 第一次注册：返回 401
            $this->log("设备首次注册请求: {$deviceId}，发送 401", 'DEBUG');

            $nonce = $this->generateNonce();
            $realm = $this->config['server_domain'];

            // 检查设备 User-Agent 是否声明安全能力
            $capability = $this->getDeviceCapability($event);

            if ($capability) {
                // 设备支持数字证书认证
                $this->log("设备支持安全能力: {$capability}", 'DEBUG');
                // TODO: 实现数字证书认证的 WWW-Authenticate 头
                // 暂时仍使用 Digest
            }

            // 基本认证：使用 Digest MD5
            $result = $this->sipServer->sendResponse($event->getTid(), 401, 'Unauthorized', [
                'WWW-Authenticate' => "Digest realm=\"{$realm}\", nonce=\"{$nonce}\", algorithm=MD5",
            ]);
            return;
        }

        // 第二次注册：验证 Authorization
        $this->log("设备携带认证信息注册: {$deviceId}", 'DEBUG');

        // TODO: 验证 Authorization 头的合法性
        // 这里简化处理，实际应该验证 response 字段
        if (!$this->validateAuthorization($event, $deviceId)) {
            $this->log("认证失败: {$deviceId}", 'ERROR');
            $this->sipServer->sendResponse($event->getTid(), 403, 'Forbidden');
            return;
        }

        // 认证成功，提取连接信息
        $connection = $event->getConnection();
        $contactIp = $connection['ip'] ?? '0.0.0.0';
        $contactPort = $connection['port'] ?? 5060;

        // 【关键】获取 Via 头的 received 参数（实际源地址）
        // 设备的 Contact 头来自它的配置（可能是设备管理界面填写的）
        // 但在以下情况下，Contact 地址不可达：
        // 1. 设备配置错误（填错IP）
        // 2. 设备有多网卡，配置了错误的网卡IP
        // 3. 设备在 NAT 后（Contact是内网IP，服务器在公网）
        // Via 的 received 参数记录了服务器看到的实际源地址，这才是可达的
        $viaHeader = $event->getHeader('Via');
        $receivedIp = null;
        $receivedPort = null;

        if ($viaHeader) {
            if (preg_match('/received=([0-9.]+)/', $viaHeader, $matches)) {
                $receivedIp = $matches[1];
            }
            if (preg_match('/rport=(\d+)/', $viaHeader, $matches)) {
                $receivedPort = (int)$matches[1];
            }
        }

        // 【决策】优先使用 received（实际源地址），因为：
        // - Contact 是设备声明的地址（可能配置错误）
        // - received 是服务器实际收到包的源地址（100%可达）
        $finalIp = $receivedIp ? : $contactIp;
        $finalPort = $receivedPort ? : $contactPort;

        $this->log("GB28181设备注册成功: {$deviceId}");
        $this->log("  Contact 声明地址: {$contactIp}:{$contactPort}", 'DEBUG');
        if ($receivedIp) {
            $this->log("  实际源地址 (received): {$receivedIp}:{$receivedPort}", 'DEBUG');
            if ($contactIp !== $receivedIp) {
                $this->log("  设备配置的IP与实际IP不符", 'WARNING');
                $this->log("     可能原因：设备配置错误、多网卡、NAT穿透", 'WARNING');
                $this->log("     → 使用实际源地址以确保可达性", 'WARNING');
            }
        }
        $this->log("  → 最终使用: {$finalIp}:{$finalPort}", 'DEBUG');

        // 在 DeviceManager 中创建或更新设备信息
        $deviceInfo = [
            'uri'           => $fromUri,
            'device_id'     => $deviceId,
            'ip'            => $finalIp,
            'port'          => $finalPort,
            'user_agent'    => $event->getHeader('User-Agent'),
            'received_ip'   => $receivedIp,  // 保存实际源地址，供调试使用
            'received_port' => $receivedPort,
            'registered_at' => time(),
            'timestamp'     => time(),
            'expires'       => $this->config['register_expires'],
        ];

        // 检查设备是否已存在
        $existingDevice = $this->deviceManager->getDevice($deviceId);
        if ($existingDevice) {
            // 更新现有设备
            $this->deviceManager->updateDeviceInfo($deviceId, $deviceInfo);
        } else {
            // 添加新设备
            $this->deviceManager->addDevice($deviceId, $deviceInfo);
        }

        $this->deviceManager->recordHeartbeat($deviceId);

        // 发送注册成功响应
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
            'Expires' => $this->config['register_expires'],
        ]);

        $stats = $this->deviceManager->getStats();
        $this->log("当前在线设备: {$stats['online']}");

        $this->postTask('register', [
            'device_id'     => $deviceId,
            'from_uri'      => $fromUri,
            'ip'            => $finalIp,
            'port'          => $finalPort,
            'user_agent'    => $event->getHeader('User-Agent'),
            'received_ip'   => $receivedIp,  // 保存实际源地址，供调试使用
            'received_port' => $receivedPort,
            'registered_at' => time(),
            'timestamp'     => time(),
            'expires'       => $this->config['register_expires'],
        ]);


        // 自动查询设备目录
        if ($this->config['catalog_auto_query']) {
            $this->queryCatalog($deviceId);
        }
    }


    /**
     * 处理SIP MESSAGE（GB28181 XML消息）
     */
    public function handleMessage(\SipEvent $event) : void
    {
//        $this->log("收到SIP MESSAGE: {$event->getFromUri()}, headers: {$event->getHeader('Call-ID')}");
        $body = $event->getBody();
        $fromUri = $event->getFromUri();
        $deviceId = $this->extractDeviceId($fromUri);

        $device = $this->deviceManager->getDeviceObject($deviceId);
        if (!$device) {
            $this->log("设备未注册: {$deviceId}", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 404, 'Not Found');
            return;
        }

        // 检查 body 是否为空
        if (empty($body)) {
            $this->log("收到空消息体，忽略", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
            return;
        }

        // GB28181 编码兼容处理
        // 问题：设备声明 UTF-8 但实际发送 GB2312/GBK 编码
        // 解决：检测实际编码并转换为 UTF-8
        $body = $this->normalizeXmlEncoding($body, $device->charset);

        // 解析XML
        $xml = @simplexml_load_string($body);
        if (!$xml) {
            $this->log("XML解析失败", 'ERROR');
            if ($this->config['debug']) {
                $this->log("原始 Body: " . $body, 'DEBUG');
            }
            $this->sipServer->sendResponse($event->getTid(), 400, 'Bad Request');
            return;
        }

        $this->postTask('sip_xml', [
            'device_id' => $deviceId,
            'xml'       => $body,
        ]);
        try {
            // 使用 MessageHandler 处理消息
            $result = $this->messageHandler->handle($xml, $deviceId, [
                'event'          => $event,
                'device_manager' => $this->deviceManager,
            ]);

            $cmdType = $result['cmd_type'] ?? 'Unknown';
            $this->log("收到消息: $deviceId -> $cmdType");

            // 根据命令类型分发处理
            $this->dispatchCommand($event, $deviceId, $result);

        } catch (\InvalidArgumentException $e) {
            $this->log("未知命令: " . $e->getMessage(), 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
        }
    }


    /**
     * 处理INVITE请求
     *
     * 区分三种场景:
     * 1. 广播模式(Broadcast): 设备在收到 Broadcast MESSAGE 后主动发送 INVITE
     *    - 通过 pendingBroadcasts 匹配（最可靠）
     *    - 通过 Subject 头包含 broadcast 关键字（兜底）
     * 2. 语音对讲(Talk): 设备主动发起INVITE, Subject包含talk (已弃用, GB28181-2022 移除)
     * 3. 视频点播: 服务器主动发起INVITE(由CommandDispatcher处理)
     */
    public function handleInvite(\SipEvent $event) : void
    {
        $fromUri = $event->getFromUri();
        $toUri = $event->getToUri();
        $deviceId = $this->extractDeviceId($fromUri);
        $channelId = $this->extractDeviceId($toUri);
        $subject = $event->getHeader('Subject') ?? '';
        $body = $event->getBody();

        $bodyLen = $body ? strlen($body) : 0;
        $contentType = $event->getContentType();
        $callId = $event->getCallId();
        $dialogId = $event->getDialogId();
        $tid = $event->getTid();
        $this->log("收到INVITE: 设备{$deviceId} 通道{$channelId} bodyLen={$bodyLen} contentType={$contentType} callId={$callId} dialogId={$dialogId} tid={$tid}");

        // 诊断：检查 body 详情
        if ($bodyLen === 0) {
            $this->log("DIAG: INVITE body is empty/null, body type=" . gettype($body));
        } else {
            $this->log("DIAG: INVITE body first 200 chars: " . substr($body, 0, 200));
        }

        // === 第一步：校验广播会话合法性（WVP 对齐） ===
        // 优先检查是否有待处理的广播会话（设备 INVITE 的 From 是 channelId）
        // 广播模式下：设备收到 Broadcast MESSAGE 后，由通道发送 INVITE
        // fromUri 的 deviceId 实际上就是 channelId
        $pendingBroadcast = $this->commandDispatcher->findPendingBroadcast($deviceId);

        if ($pendingBroadcast) {
            // 广播模式：通过 pendingBroadcasts 匹配到
            // NVR 代替通道发送 INVITE，fromUri 是 NVR device_id，实际 channel_id 在 pendingBroadcast 中
            $this->log("广播 INVITE 匹配: fromDevice={$deviceId}, channelId={$pendingBroadcast['channel_id']}");
            $this->handleBroadcastInvite($event, $deviceId, $pendingBroadcast, $body);
            return;
        }

        // 检查设备是否在线
        $device = $this->deviceManager->getDevice($deviceId);
        if (!$device || !$device['registered']) {
            $this->log("设备未注册: {$deviceId}", 'ERROR');
            $this->sipServer->sendCallAnswer($tid, 404, null, 'Not Found');
            return;
        }

        // 判断是否为语音对讲请求（通过 Subject 头）
        $isBroadcast = stripos($subject, 'broadcast') !== false;
        $isTalk = stripos($subject, 'talk') !== false;

        if ($isBroadcast) {
            // Subject 含 broadcast 但没有 pendingBroadcast，说明没有等待中的广播
            // WVP 对齐：回复 403 Forbidden
            $this->log("广播 INVITE 但无待处理广播: deviceId={$deviceId}, 回复 403", 'WARNING');
            $this->sipServer->sendCallAnswer($tid, 403, null, 'Forbidden - No pending broadcast');
            return;
        }

        if ($isTalk) {
            $this->handleVoiceInvite($event, $deviceId, $channelId, $body, 'talk');
        } else {
            // 常规视频INVITE(目前简化处理)
            $this->log("视频INVITE: {$deviceId} -> {$channelId}");
            $this->sipServer->sendCallAnswer($tid, 180, null, 'Ringing');
            $this->sipServer->sendCallAnswer($tid, 200, null, 'OK');
            $this->log("视频会话已建立");
        }
    }

    /**
     * 处理会话结束BYE
     */
    public function handleBye(\SipEvent $event) : void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $callId = $event->getCallId();

        $this->log("收到 BYE: $deviceId (Call-ID: $callId)");

        // 清理已处理的 INVITE 200 OK 记录（防止内存泄漏）
        unset($this->processedInviteCallIds[$callId]);

        // 通知外部系统会话结束
        $this->postTask('session_bye', [
            'device_id' => $deviceId,
            'call_id'   => $callId,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理ACK
     *
     * 广播模式（WVP 对齐）：
     * 当 broadcastPushAfterAck=true 时，收到设备 ACK 后才触发 startSendRtp
     * 让 ZLM 向设备发送音频 RTP 流。
     */
    public function handleAck(\SipEvent $event) : void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $callId = $event->getCallId();
        $this->log("ACK确认: {$deviceId}, callId={$callId}", 'DEBUG');

        // === 广播模式：ACK 触发推流 ===
        if (isset($this->pendingBroadcastAck[$callId])) {
            $ackInfo = $this->pendingBroadcastAck[$callId];
            unset($this->pendingBroadcastAck[$callId]);

            $this->log("广播 ACK 收到，触发 startSendRtp: sessionId={$ackInfo['session_id']}, callId={$callId}");
            $this->dispatchStartSendRtp($ackInfo);
        }
    }

    /**
     * 处理INFO（PTZ控制等）
     */
    public function handleInfo(\SipEvent $event) : void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $this->log("INFO消息: {$deviceId}", 'DEBUG');
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理UPDATE请求
     */
    public function handleUpdate(\SipEvent $event) : void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $this->log("UPDATE请求: {$deviceId}", 'DEBUG');

        // UPDATE通常用于会话参数更新（如媒体参数）
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理REFER转接
     */
    public function handleRefer(\SipEvent $event) : void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $this->log("REFER转接: {$deviceId}", 'DEBUG');

        // REFER用于呼叫转移
        $this->sipServer->sendResponse($event->getTid(), 202, 'Accepted');
    }

    /**
     * 处理订阅请求（SUBSCRIBE）
     *
     * 触发时机：当国标设备向服务器发送 SUBSCRIBE 请求时
     * 事件类型：EXOSIP_IN_SUBSCRIPTION_NEW（IN_ 前缀表示 incoming 入站请求）
     *
     * 使用场景（较少见，但需要支持）：
     * - 下级平台向上级平台订阅目录变更
     * - 设备订阅平台的报警推送
     * - 级联模式下的事件订阅
     *
     * 订阅类型（通过 Event 头域判断）：
     * - Event: Catalog        订阅目录变更
     * - Event: Alarm          订阅报警事件
     * - Event: MobilePosition 订阅位置上报（平台作为位置源）
     * - Event: presence       兼容旧版位置订阅
     *
     * @param \SipEvent $event SUBSCRIBE 事件
     */
    public function handleSubscribe(\SipEvent $event) : void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $eventType = $event->getHeader('Event') ?? 'unknown';
        $expires = $event->getExpires() ?? 3600;
        $body = $event->getBody();
        $callId = $event->getCallId();
        $dialogId = $event->getDialogId();

        $this->log("收到 SUBSCRIBE: 设备={$deviceId}, Event={$eventType}, Expires={$expires}");

        // 检查设备是否注册
        $device = $this->deviceManager->getDeviceObject($deviceId);
        if (!$device) {
            $this->log("SUBSCRIBE 来自未注册设备: {$deviceId}", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 404, 'Not Found');
            return;
        }

        // 处理取消订阅（Expires: 0）
        if ($expires === 0) {
            $this->log("取消订阅: {$deviceId}, Event: {$eventType}");

            // 从设备管理器移除订阅
            $subscriptionType = $this->mapEventToSubscriptionType($eventType);
            if ($subscriptionType) {
                $this->deviceManager->removeSubscription($deviceId, $subscriptionType);
            }

            // 通知业务系统
            $this->postTask('subscription_cancelled', [
                'device_id'  => $deviceId,
                'event_type' => $eventType,
                'call_id'    => $callId,
                'timestamp'  => time(),
            ]);

            $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
                'Expires' => 0,
            ]);
            return;
        }

        $this->log("处理订阅: {$deviceId}, Event: {$eventType}, Expires: {$expires}");
        $handler = new DeviceToServerSubscribeHandler();
        // 根据 Event 类型处理不同的订阅
        switch (strtolower($eventType)) {
            case 'catalog':
                $handler->handleCatalogSubscribe($event, $deviceId, $expires, $body);
                break;

            case 'alarm':
                $handler->handleAlarmSubscribe($event, $deviceId, $expires, $body);
                break;

            case 'mobileposition':
            case 'presence':
                $handler->andleMobilePositionSubscribe($event, $deviceId, $expires, $body);
                break;

            default:
                // 未知订阅类型，仍然接受但记录日志
                $this->log("未知订阅类型: {$eventType}", 'WARNING');

                $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
                    'Expires' => $expires,
                ]);

                // 通知业务系统
                $this->postTask('subscription_unknown', [
                    'device_id'  => $deviceId,
                    'event_type' => $eventType,
                    'expires'    => $expires,
                    'call_id'    => $callId,
                    'dialog_id'  => $dialogId,
                    'body'       => $body,
                    'timestamp'  => time(),
                ]);
                break;
        }
    }

    /**
     * 将 Event 头映射到订阅类型
     *
     * @param string $eventType Event 头的值
     * @return string|null 订阅类型
     */
    private function mapEventToSubscriptionType(string $eventType) : ?string
    {
        $map = [
            'catalog'        => 'catalog',
            'alarm'          => 'alarm',
            'mobileposition' => 'mobile_position',
            'presence'       => 'mobile_position',
        ];

        return $map[strtolower($eventType)] ?? null;
    }

    /**
     * 处理通知消息（NOTIFY）
     *
     * NOTIFY 用于异步通知，设备主动向平台发送状态信息。
     *
     * GB28181 订阅/通知场景说明：
     * ===========================
     * 1. 平台主动订阅设备：
     *    - 平台调用 $sipServer->subscribe() 向设备发送 SUBSCRIBE
     *    - 设备响应 200 OK（触发 onResponse 回调）
     *    - 设备发生事件时主动推送 NOTIFY（触发本 handleNotify 回调）
     *
     * 2. 设备主动订阅平台（较少见）：
     *    - 设备向平台发送 SUBSCRIBE（触发 handleSubscribe 回调）
     *    - 平台响应 200 OK
     *    - 平台发生变化时向设备发送 NOTIFY
     *
     * 订阅事件类型（通过 Event 头域判断）：
     * =====================================
     * - Event: Catalog        目录变更通知（设备/通道增删改）
     * - Event: Alarm          报警事件订阅通知
     * - Event: MobilePosition 移动设备位置订阅通知
     * - Event: presence       兼容旧版位置订阅（GB28181-2016）
     *
     * XML 命令通知（通过 CmdType 判断）：
     * ==================================
     * - MediaStatus: GB28181-2022 媒体状态通知（截图完成/流保活）
     * - 其他自定义命令类型
     *
     * 处理流程：
     * =========
     * 1. 优先检查 Event 头域（订阅事件）
     * 2. 如果没有 Event 或不识别，解析 XML CmdType（命令通知）
     * 3. 通过 MessageHandler 分发到对应的 CommandType 处理
     * 4. 检查 Subscription-State 头判断订阅是否终止
     */
    public function handleNotify(\SipEvent $event) : void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $eventType = $event->getHeader('Event') ?? '';
        $subscriptionState = $event->getHeader('Subscription-State') ?? '';
        $body = $event->getBody();
        $device = $this->deviceManager->getDeviceObject($deviceId);

        if (!$device) {
            $this->log("NOTIFY 来自未注册设备: {$deviceId}", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 404, 'Not Found');
            return;
        }

        $this->log("收到 NOTIFY: 设备={$deviceId}, Event={$eventType}, State={$subscriptionState}");

        // 使用 SubscribeNotifyCommand 统一处理订阅通知（Event 头方式）
        if (SubscribeNotifyCommand::isSupportedEvent($eventType)) {
            $this->handleSubscribeNotify($event, $deviceId, $eventType, $subscriptionState, $body);
            return;
        }

        // 调试日志
        if ($body && ($this->config['debug'] ?? false)) {
            $this->log("NOTIFY Body: {$body}", 'DEBUG');
        }

        // 处理 XML 命令通知（无 Event 头或未识别的 Event，通过 CmdType 判断）
        if ($body) {
            // 规范化编码
            $body = $this->normalizeXmlEncoding($body, $device->charset);

            // 解析 XML
            $xml = @simplexml_load_string($body);
            if ($xml) {
                try {
                    // 使用 MessageHandler 统一处理（和 handleMessage 相同的模式）
                    $result = $this->messageHandler->handle($xml, $deviceId, [
                        'event'          => $event,
                        'device_manager' => $this->deviceManager,
                    ]);

                    $cmdType = $result['cmd_type'] ?? 'Unknown';
                    $this->log("收到 NOTIFY 命令: $deviceId -> $cmdType");

                    // 分发命令结果
                    $this->dispatchCommand($event, $deviceId, $result);
                    return;

                } catch (\InvalidArgumentException $e) {
                    // 未知的命令类型，记录日志但不报错
                    $this->log("未知 NOTIFY 命令: " . $e->getMessage(), 'WARNING');
                }
            }
        }

        // 兜底：返回 200 OK
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }


    /**
     * 统一处理订阅通知（使用 SubscribeNotifyCommand）
     *
     * 使用 Command 模式统一处理所有订阅相关的 NOTIFY 消息：
     * - Catalog: 目录变更通知
     * - Alarm: 报警事件通知
     * - MobilePosition/presence: 移动设备位置通知
     *
     * @param \SipEvent $event NOTIFY 事件
     * @param string $deviceId 设备ID
     * @param string $eventType Event 头的值
     * @param string $subscriptionState Subscription-State 头的值
     * @param string $body XML 消息体
     */
    private function handleSubscribeNotify(\SipEvent $event, string $deviceId, string $eventType, string $subscriptionState, string $body) : void
    {
        $eventTypeDesc = SubscribeNotifyCommand::getEventTypeDesc($eventType);
        $this->log("{$eventTypeDesc}通知: {$deviceId}, State: {$subscriptionState}");

        $device = $this->deviceManager->getDeviceObject($deviceId);
        if (!$device) {
            $this->log("设备未注册: {$deviceId}", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
            return;
        }

        // 检查订阅状态是否终止
        $isTerminated = stripos($subscriptionState, 'terminated') !== false;
        if ($isTerminated) {
            $subscriptionType = $this->mapEventToSubscriptionType($eventType);
            if ($subscriptionType) {
                $this->log("{$eventTypeDesc}订阅已终止: {$deviceId}");
                $this->deviceManager->removeSubscription($deviceId, $subscriptionType);
            }
        }

        // 消息体为空时直接返回
        if (empty($body)) {
            $this->log("{$eventTypeDesc}通知消息体为空", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
            return;
        }

        // 规范化编码并解析 XML
        $body = $this->normalizeXmlEncoding($body, $device->charset);
        $xml = @simplexml_load_string($body);

        if (!$xml) {
            $this->log("{$eventTypeDesc}通知 XML 解析失败", 'ERROR');
            $this->sipServer->sendResponse($event->getTid(), 400, 'Bad Request');
            return;
        }

        // 使用 SubscribeNotifyCommand 统一处理
        $subscribeNotifyCommand = new SubscribeNotifyCommand();
        $result = $subscribeNotifyCommand->handle($xml, $deviceId, [
            'event_type'         => $eventType,
            'subscription_state' => $subscriptionState,
            'sip_event'          => $event,
        ]);

        $notifyType = $result['notify_type'] ?? $eventType;

        // 根据通知类型记录日志
        switch ($notifyType) {
            case 'catalog':
                $deviceCount = $result['device_count'] ?? 0;
                $eventInXml = $result['event_desc'] ?? $result['event'] ?? '';
                $this->log("  事件类型: {$eventInXml}, 通道数: {$deviceCount}");
                break;

            case 'alarm':
                $alarmMethodDesc = $result['alarm_method_desc'] ?? '';
                $priorityDesc = $result['alarm_priority_desc'] ?? '';
                $this->log("  报警类型: {$alarmMethodDesc}, 优先级: {$priorityDesc}");
                if (!empty($result['alarm_description'])) {
                    $this->log("  报警描述: {$result['alarm_description']}");
                }
                break;

            case 'mobile_position':
                $lon = $result['longitude'] ?? '';
                $lat = $result['latitude'] ?? '';
                $time = $result['time'] ?? '';
                $this->log("  位置: 经度={$lon}, 纬度={$lat}, 时间={$time}");
                break;
        }

        // 推送通知到业务系统
        $scene = "{$notifyType}_notify";
        $this->postTask($scene, $result);

        $this->log("✓ {$eventTypeDesc}通知已处理: {$deviceId}");

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理 MediaStatus 通知 (GB28181-2022)
     *
     * 处理两种通知类型：
     * - SnapshotComplete: 图像抓拍完成通知
     * - Keepalive: 媒体流心跳通知
     */
    private function handleMediaStatusReport(\SipEvent $event, string $deviceId, array $result) : void
    {
        $notifyType = $result['notify_type'] ?? '';
        $this->log("MediaStatus 通知: {$deviceId}, Type: {$notifyType}");

        if ($notifyType === 'SnapshotComplete') {
            // 图像抓拍完成通知
            $sessionId = $result['session_id'] ?? '';
            $fileUrl = $result['file_url'] ?? '';

            $this->log("抓拍完成: SessionID={$sessionId}, URL={$fileUrl}");

            // 推送到业务系统
            $this->postTask('snapshot_complete', [
                'device_id'   => $deviceId,
                'session_id'  => $sessionId,
                'file_url'    => $fileUrl,
                'notify_type' => 'SnapshotComplete',
                'timestamp'   => time(),
            ]);
        } else if ($notifyType === 'Keepalive') {
            // 媒体流心跳通知
            $ssrc = $result['ssrc'] ?? '';
            $bitRate = $result['bit_rate'] ?? '';
            $frameRate = $result['frame_rate'] ?? '';
            $packetLoss = $result['packet_loss'] ?? '';

            $this->log("媒体流心跳: SSRC={$ssrc}, BitRate={$bitRate}, FrameRate={$frameRate}, Loss={$packetLoss}", 'DEBUG');

            // 可选: 推送媒体流状态到业务系统
            $this->postTask('media_status', [
                'device_id'   => $deviceId,
                'ssrc'        => $ssrc,
                'bit_rate'    => $bitRate,
                'frame_rate'  => $frameRate,
                'packet_loss' => $packetLoss,
                'notify_type' => 'Keepalive',
                'timestamp'   => time(),
            ]);
        }

        // 发送 200 OK
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }


    /**
     *  关键：处理设备对 INVITE 的 200 OK 响应（含 SDP）
     *  以及 MESSAGE 查询命令的 200 OK 响应
     */
    public function handleResponse(\SipEvent $event) : void
    {
        $code = $event->getCode();
        $type = $event->getType();
        $callId = $event->getCallId();

        $this->log("收到响应: Type=$type Code=$code Call-ID=$callId");

        // 根据响应码处理
        if ($code >= 200 && $code < 300) {
            //  成功响应
            if ($code == 200) {
                // INVITE 的 200 OK（含 SDP）- EXOSIP_CALL_ANSWERED=8
                // 注意: EXOSIP_CALL_RINGING=7 是 180 Ringing（临时响应），不应处理
                // ACK 只应在收到最终响应（200 OK）时发送（RFC 3261 Section 13.2.2.4）
                if ($type == EXOSIP_CALL_ANSWERED) {
                    $this->handleInviteResponse($event);
                } // MESSAGE 的 200 OK（查询命令已接收）
                else if ($type == EXOSIP_MESSAGE_ANSWERED || $type == EXOSIP_CALL_MESSAGE_ANSWERED) {
                    $this->handleMessageResponse($event);
                } else {
                    if ($this->config['debug'] ?? false) {
                        $this->log("请求成功: Type=$type Code=$code (未处理)", 'DEBUG');
                    }
                }
            }
        } else if ($code >= 100 && $code < 200) {
            // 临时响应 (1xx) - 仅记录日志
            if ($this->config['debug'] ?? false) {
                $this->log("临时响应: Type=$type Code=$code (如 180 Ringing)", 'DEBUG');
            }
        } else if ($code >= 400) {
            // 错误响应
            $this->log("请求失败: Type=$type Code=$code", 'WARNING');

            // 可以根据不同类型记录失败信息
            if ($type == EXOSIP_MESSAGE_REQUESTFAILURE ||
                $type == EXOSIP_MESSAGE_SERVERFAILURE ||
                $type == EXOSIP_MESSAGE_GLOBALFAILURE) {
                $this->log("MESSAGE 请求失败,可能是设备不支持该命令", 'WARNING');
            }
        }
    }


    /**
     * 处理 INVITE 的 200 OK 响应（含设备 SDP）
     *
     * 关键作用：
     * - 提取设备 SSRC（y= 字段）
     * - 提取设备媒体接收地址（c= 字段）
     * - 通知业务系统媒体流已就绪
     * - 通知 ZLM 更新 SSRC（用于流关联）
     * - 对于语音对讲：更新会话状态并发送 voice_established 通知
     *
     * 重要说明（From/To URI 问题）：
     * 对于服务器主动发起的 INVITE（视频点播、语音对讲），200 OK 保留原始 INVITE 的 From/To：
     *   From = 服务器（发起方）
     *   To   = 设备（被邀请方）
     * 因此不能简单地从 fromUri 提取 deviceId。
     * 正确做法是通过 call_id 从 CommandDispatcher 的 activeSessions 中查找会话，
     * 直接获取 device_id 和 channel_id。
     *
     * 重传处理（RFC 3261 Section 13.2.2.4）：
     * 当 UAS（设备）未收到 ACK 时，会按定时器重传 200 OK。
     * 对于重传的 200 OK，UAC（服务器）必须重新发送 ACK，但不应重复执行业务逻辑。
     * 通过 processedInviteCallIds 跟踪已处理的 call_id 来实现去重。
     */
    private function handleInviteResponse(\SipEvent $event) : void
    {
        $callId = $event->getCallId();
        $dialogId = $event->getDialogId();

        // === 重传检测 ===
        // 如果此 call_id 的 200 OK 已经处理过，说明这是设备重传的 200 OK
        // 只需重发 ACK，不再重复执行业务逻辑（避免重复 postTask、重复更新 dialog_id）
        if (isset($this->processedInviteCallIds[$callId])) {
            $cachedDialogId = $this->processedInviteCallIds[$callId];
            $effectiveDialogId = ($dialogId > 0) ? $dialogId : $cachedDialogId;
            $this->log("200 OK 重传检测: call_id={$callId}, 重发 ACK (dialog_id={$effectiveDialogId})", 'DEBUG');
            if ($effectiveDialogId > 0) {
                $this->sipServer->sendAck($effectiveDialogId);
            }
            return;
        }

        // 通过 call_id 从 CommandDispatcher 查找活跃会话
        // 这避免了 From/To URI 反转问题（server-initiated INVITE: From=server, To=device）
        $activeSession = $this->commandDispatcher->findActiveSessionByCallId($callId);

        // 从 activeSession 获取正确的 device_id 和 channel_id
        if ($activeSession) {
            $deviceId = $activeSession['device_id'];
            $channelId = $activeSession['channel_id'];
        } else {
            // 兜底：如果 activeSession 找不到（可能是设备主动发起的 INVITE），
            // 从 To URI 提取（设备主动 INVITE 时 From=device, To=server，但我们不太需要处理这种情况）
            $fromUri = $event->getFromUri();
            $toUri = $event->getToUri();
            $deviceId = $this->extractDeviceId($fromUri);
            $channelId = $this->extractDeviceId($toUri);
        }

        $this->log("收到 INVITE 200 OK: 设备 {$deviceId}, 通道 {$channelId}, Call-ID: {$callId}, Dialog-ID: {$dialogId}");

        // 关键修复：更新会话的 dialog_id（修复 BYE 时 dialog_id=-1 的问题）
        // 背景：sendInvite() 返回时 dialog_id=-1（对话尚未建立）
        //       收到 200 OK 时 eXosip 才分配有效的 dialog_id
        //       必须在此更新 activeSessions，否则后续 BYE 会失败
        if ($dialogId > 0) {
            $this->commandDispatcher->updateSessionDialog($callId, $dialogId);
            $this->log("已更新会话 dialog_id: call_id={$callId} -> dialog_id={$dialogId}");
        }

        // === 关键：立即发送 ACK（RFC 3261 要求尽快发送）===
        // ACK 必须在收到 200 OK 后立即发送，不能被任何其他操作延迟。
        // 否则设备会因为超时而重传 200 OK，导致会话建立失败。
        if ($dialogId > 0) {
            $this->sipServer->sendAck($dialogId);
            $this->log("ACK 已发送: dialog_id={$dialogId}");
        }

        // 记录此 call_id 已处理，用于后续重传检测
        $this->processedInviteCallIds[$callId] = $dialogId;

        // 解析设备返回的 SDP
        $sdp = $event->getSdp();
        if (!$sdp) {
            $this->log("INVITE 200 OK 缺少 SDP", 'WARNING');
            // ACK 已在上面发送，此处直接返回
            return;
        }

        // 提取设备 SSRC（GB28181 扩展字段）
        $deviceSsrc = $sdp['gb28181']['ssrc'] ?? null;

        // 提取设备媒体接收地址
        // RFC 4566: c= line (connection info) is authoritative for media connection address.
        // Fall back to o= line (origin) if c= is absent.
        $deviceIp = $sdp['connection']['addr'] ?? $sdp['origin']['addr'] ?? null;
        $devicePort = $sdp['medias'][0]['port'] ?? null;

        if ($this->config['debug']) {
            $this->log("设备 SDP 解析结果:", 'DEBUG');
            $this->log("  SSRC: {$deviceSsrc}", 'DEBUG');
            $this->log("  媒体地址: {$deviceIp}:{$devicePort}", 'DEBUG');
            $this->log('sdp:' . json_encode($sdp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'DEBUG');
        }

        // 检查是否为语音对讲会话（通过 activeSession 的 type 字段判断）
        $isVoiceTalk = $activeSession && $activeSession['type'] === 'talk';

        if ($isVoiceTalk) {
            // 语音对讲会话处理（ACK 已在上面发送，此处只做业务逻辑）
            $this->handleVoiceTalkEstablished($activeSession, $dialogId, $deviceIp, $devicePort);
        } else {
            // 视频流会话处理（ACK 已在上面发送，此处只做业务逻辑）
            // 通知业务系统：媒体流已就绪
            $this->postTask('media_ready', [
                'device_id'   => $deviceId,
                'call_id'     => $callId,
                'dialog_id'   => $dialogId,
                'device_ssrc' => $deviceSsrc,
                'device_ip'   => $deviceIp,
                'device_port' => $devicePort,
                'sdp'         => $sdp,
                'timestamp'   => time(),
            ]);

            $this->log("媒体流就绪通知已发送: {$deviceId} SSRC={$deviceSsrc}");
        }
    }

    /**
     * 处理 MESSAGE 的 200 OK 响应（空 body）
     *
     * 关键作用：
     * - 确认设备已接收控制指令（PTZ、录像控制等）
     * - 通知业务系统指令执行成功
     *
     * 注意：
     * - MESSAGE 200 OK 通常没有 body（符合 SIP 协议）
     * - 实际的执行结果会通过后续的 MESSAGE 请求返回（带 XML body）
     * - 这里只是确认"设备收到了指令"，不是"指令执行完成"
     */
    private function handleMessageResponse(\SipEvent $event) : void
    {
        $code = $event->getCode();
        $callId = $event->getCallId();
        $toUri = $event->getToUri();
        $deviceId = $this->extractDeviceId($toUri);

        if ($code !== 200) {
            $this->log("MESSAGE 响应失败: Code={$code}, Device={$deviceId}", 'WARNING');
            return;
        }

        // 从 headers 中提取 CSeq 获取原始方法名
        $cseqHeader = $event->getHeader('CSeq');
        $cseqNumber = null;
        $method = 'MESSAGE';

        if ($cseqHeader && preg_match('/(\d+)\s+(\w+)/', $cseqHeader, $matches)) {
            $cseqNumber = (int)$matches[1];
            $method = $matches[2];
        }

        $this->log("收到 MESSAGE 200 OK: 设备 {$deviceId}, Call-ID: {$callId}, CSeq: {$cseqNumber}");

        // 通知业务系统：设备已确认收到控制指令
        // 业务系统可以根据 call_id 或 cseq 关联原始请求
        $this->postTask('command_confirmed', [
            'device_id'   => $deviceId,
            'call_id'     => $callId,
            'cseq'        => $cseqNumber,
            'status_code' => $code,
            'method'      => $method,
            'timestamp'   => time(),
        ]);

        if ($this->config['debug']) {
            $this->log("✓ 设备确认收到指令: {$deviceId} (CSeq: {$cseqNumber})", 'DEBUG');
        }
    }

    /**
     * 处理超时事件
     */
    public function handleTimeout(\SipEvent $event) : void
    {
        $type = $event->getType();
        $toUri = $event->getToUri();
        $deviceId = $this->extractDeviceId($toUri);

        $this->log("请求超时: Type={$type} Device={$deviceId}", 'WARNING');

        // 可以标记设备为不可达或离线
        if ($deviceId) {
            $this->log("设备请求超时: $deviceId", 'WARNING');
            // 可选：更新设备状态
            // $this->deviceManager->markDeviceUnreachable($deviceId);
        }
    }

    /**
     * 处理错误事件
     */
    /**
     * 处理错误事件(接收字符串错误消息)
     */
    public function handleError(string $errorMsg) : void
    {
        $this->log("Event callback error: $errorMsg", 'ERROR');

        // 可以根据错误消息进行不同的处理
        // 例如：Fatal error, Exception等
    }

    // ========== 具体命令处理 ==========

    /**
     * 处理心跳保活
     */
    private function handleKeepalive(\SipEvent $event, string $deviceId, array $data) : void
    {
        $this->log("心跳: $deviceId");

        // 更新心跳时间（用于超时检测）
        $this->deviceManager->recordHeartbeat($deviceId);

        // 可选：检查设备状态
        $status = $data['status'] ?? 'OK';
        if ($status !== 'OK') {
            $this->log("设备状态异常: $deviceId - $status", 'WARNING');
        }

        // 异步更新心跳到 Redis/数据库
        $this->postTask('update_heartbeat', [
            'device_id' => $deviceId,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    private function handleRecordInfo(\SipEvent $event, string $deviceId, array $data) : void
    {
        $this->postTask('record_info', [
            'device_id'   => $deviceId,
            'record_info' => $data,
            'timestamp'   => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理目录响应
     */
    private function handleCatalog(\SipEvent $event, string $deviceId, array $result) : void
    {
        $this->log("目录响应: $deviceId");

        $sumNum = $result['sum_num'] ?? 0;
        $this->log("设备总数: $sumNum");

        $items = $result['device_list'] ?? [];

        $this->log("收到 " . count($items) . " 个设备/通道");

        // 调试:打印通道详情
        if ($this->config['debug'] ?? false) {
            foreach ($items as $item) {
                $channelId = $item['DeviceID'] ?? 'unknown';
                $channelName = $item['Name'] ?? 'unknown';
                $this->log("  通道: $channelId - $channelName", 'DEBUG');
            }
        }

        // 更新 DeviceManager 中的通道列表
        $device = $this->deviceManager->getDeviceObject($deviceId);
        if ($device && !empty($device->filterChannelTypes)) {
            // 根据device->filterChannelTypes 过滤通道
            $items = array_filter($items, function ($item) use ($device) {
                $channelId = $item['DeviceID'] ?? 'unknown';
                $typeCode = (int)substr($channelId, 10, 3);
                return in_array($typeCode, $device->filterChannelTypes);
            });
            $device->setChannels($items);
            $this->log("已更新设备 {$deviceId} 的通道列表到内存", 'DEBUG');
        }

        // 异步保存目录到数据库
        $this->postTask('device_catalog', [
            'device_id' => $deviceId,
            'sum_num'   => $sumNum,
            'devices'   => $items,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理设备信息响应
     */
    private function handleDeviceInfo(\SipEvent $event, string $deviceId, array $result) : void
    {
        $this->log("设备信息: $deviceId");

        $deviceInfo = $result['device_info'] ?? [];
        $info = [
            'name'         => $deviceInfo['DeviceName'] ?? '',
            'manufacturer' => $deviceInfo['Manufacturer'] ?? '',
            'model'        => $deviceInfo['Model'] ?? '',
            'firmware'     => $deviceInfo['Firmware'] ?? '',
            'channel'      => $deviceInfo['Channel'] ?? 0,
        ];

        $this->deviceManager->updateDeviceInfo($deviceId, ['info' => $info]);

        $this->log("  名称: {$info['name']}");
        $this->log("  厂商: {$info['manufacturer']}");

        $this->postTask('device_info', [
            'device_id'   => $deviceId,
            'device_info' => $deviceInfo,
            'timestamp'   => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    public function handleDeviceControl(\SipEvent $event, string $deviceId, array $result)
    {
        $resultStr = json_encode($result);
        $this->log("设备控制: $deviceId, result={$resultStr}");

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理设备状态响应
     */
    private function handleDeviceStatus(\SipEvent $event, string $deviceId, array $data) : void
    {
        $this->log("设备状态: $deviceId");

        $online = $data['online'] ?? 'unregistered';
        $status = $data['status'] ?? 'OK';

        $this->log("  在线: $online, 状态: $status");

        $this->postTask('device_status', [
            'device_id' => $deviceId,
            'online'    => $online,
            'status'    => $status,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理报警信息
     */
    private function handleAlarm(\SipEvent $event, string $deviceId, array $data) : void
    {
        $this->log("报警信息: $deviceId", 'WARNING');

        $alarmPriority = $data['alarm_priority'] ?? '1';
        $alarmMethod = $data['alarm_method'] ?? 'Unknown';

        $this->log("  优先级: $alarmPriority, 方式: $alarmMethod");

        // 异步推送报警信息
        $this->postTask('alarm', [
            'event'     => 'alarm',
            'device_id' => $deviceId,
            'priority'  => $alarmPriority,
            'method'    => $alarmMethod,
            'data'      => $data,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    // handleMobilePositionReport
    private function handleMobilePositionReport(\SipEvent $event, string $deviceId, array $data) : void
    {
        $this->log("【移动位置通知】设备ID=$deviceId,数据=" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->postTask('mobile_position_report', [
            'event'     => 'mobile_position_report',
            'device_id' => $deviceId,
            'data'      => $data,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }


    #endregion


    /**
     * 分发命令到具体处理方法
     */
    private function dispatchCommand(\SipEvent $event, string $deviceId, array $result) : void
    {
        $cmdType = $result['cmd_type'];

        switch ($cmdType) {
            case 'Keepalive':
                $this->handleKeepalive($event, $deviceId, $result);
                break;
            case 'Catalog':
                $this->handleCatalog($event, $deviceId, $result);
                break;
            case 'DeviceInfo':
                $this->handleDeviceInfo($event, $deviceId, $result);
                break;
            case 'DeviceControl':
                $this->handleDeviceControl($event, $deviceId, $result);
                break;
            case 'DeviceStatus':
                $this->handleDeviceStatus($event, $deviceId, $result);
                break;
            case 'RecordInfo':
                $this->handleRecordInfo($event, $deviceId, $result);
                break;
            case 'Alarm':
                $this->handleAlarm($event, $deviceId, $result);
                break;
            case 'MobilePosition':
                $this->handleMobilePositionReport($event, $deviceId, $result);
                break;
            case 'MediaStatus':
                $this->handleMediaStatusReport($event, $deviceId, $result);
                break;
            case 'Broadcast':
                // 设备确认收到广播通知（Response: OK），后续设备会主动发送 INVITE
                $broadcastResult = $result['result'] ?? 'unknown';
                $broadcastChannelId = $result['channel_id'] ?? '';
                $this->log("广播响应: 设备={$deviceId}, 通道={$broadcastChannelId}, 结果={$broadcastResult}");
                $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
                break;
            case 'PresetQuery':
                $this->log("预置位查询响应: $deviceId, 数量=" . ($result['num'] ?? 0));
                $this->postTask('preset_query_result', [
                    'device_id'   => $deviceId,
                    'preset_list' => $result['preset_list'] ?? [],
                    'num'         => $result['num'] ?? 0,
                    'timestamp'   => time(),
                ]);
                $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
                break;
            case 'ConfigDownload':
                $this->log("配置查询响应: $deviceId");
                $this->postTask('config_download_result', [
                    'device_id'   => $deviceId,
                    'result'      => $result['result'] ?? '',
                    'basic_param' => $result['basic_param'] ?? [],
                    'timestamp'   => time(),
                ]);
                $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
                break;
            default:
                $this->log("未处理的命令: $cmdType", 'WARNING');
                $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
        }
    }

    /**
     * 处理语音 INVITE（广播模式 + 对讲模式兼容）
     *
     * 广播模式流程 (GB28181-2016/2022 推荐):
     * 1. 服务端发送 Broadcast MESSAGE → 设备
     * 2. 设备处理后发送 INVITE → 服务端 (本方法处理)
     * 3. 服务端回复 200 OK (带 SDP) → 设备
     * 4. 设备发送 ACK → 服务端
     * 5. ZLM 向设备推送音频流
     *
     * 对讲模式流程 (GB28181-2022 已移除，保留兼容):
     * 转发到 API 处理
     */
    /**
     * 处理 Talk 模式的设备 INVITE
     *
     * Talk 模式流程：设备主动发送 INVITE 给服务端，包含 SDP Offer。
     * 服务端解析 SDP 后转发到 API 层处理。
     *
     * @param \SipEvent $event SIP INVITE 事件
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string|null $sdpBody SDP body
     * @param string $mode 模式 (此处仅 'talk')
     */
    private function handleVoiceInvite(\SipEvent $event, string $deviceId, string $channelId, ?string $sdpBody, string $mode) : void
    {
        $bodyLen = $sdpBody ? strlen($sdpBody) : 0;
        $this->log("语音INVITE(Talk): {$deviceId} 模式:{$mode}, bodyLen={$bodyLen}");

        // === Talk 模式：需要解析设备 SDP ===
        if (!$sdpBody) {
            $this->log("Talk模式INVITE缺少SDP", 'ERROR');
            $this->sipServer->sendCallAnswer($event->getTid(), 400, null, 'Bad Request - Missing SDP');
            return;
        }

        // 使用原生 SDP 解析器（支持 GB28181 扩展）
        $deviceSdp = \ExoSip::parseSdp($sdpBody);
        if (!$deviceSdp) {
            $this->log("SDP解析失败", 'ERROR');
            $this->sipServer->sendCallAnswer($event->getTid(), 400, null, 'Bad Request - Invalid SDP');
            return;
        }

        // 提取设备 SDP 字段
        $deviceIp = $deviceSdp['connection']['addr'] ?? null;
        $devicePort = isset($deviceSdp['medias'][0]) ? $deviceSdp['medias'][0]['port'] : null;
        $transport = isset($deviceSdp['medias'][0]) ? $deviceSdp['medias'][0]['proto'] : 'RTP/AVP';

        if (!$deviceIp || !$devicePort) {
            $this->log("设备SDP缺少IP或端口", 'ERROR');
            $this->sipServer->sendCallAnswer($event->getTid(), 400, null, 'Bad Request - Missing IP/Port');
            return;
        }

        $this->log("设备音频: {$deviceIp}:{$devicePort} (传输:{$transport})");

        // === Talk 模式: 转发到 API 处理 ===
        $mediaMode = 'sendrecv';
        if (isset($deviceSdp['medias'][0]['attributes'])) {
            $attrs = $deviceSdp['medias'][0]['attributes'];
            if (isset($attrs['sendonly'])) $mediaMode = 'sendonly';
            if (isset($attrs['recvonly'])) $mediaMode = 'recvonly';
            if (isset($attrs['sendrecv'])) $mediaMode = 'sendrecv';
        }

        $this->postTask('voice_invite', [
            'device_id'   => $deviceId,
            'channel_id'  => $channelId,
            'mode'        => $mode,
            'device_ip'   => $deviceIp,
            'device_port' => $devicePort,
            'transport'   => $transport,
            'media_mode'  => $mediaMode,
            'tid'         => $event->getTid(),
            'timestamp'   => time(),
        ]);

        $this->log("语音对讲请求已转发到API项目处理");
    }

    /**
     * 处理广播模式的设备 INVITE（WVP 对齐流程）
     *
     * 当设备收到 Broadcast MESSAGE 后发送 INVITE 时调用。
     * 流程（对齐 WVP-PRO processAudioBroadcastInvite）：
     *
     * 第二步：回复 100 Trying（防止设备重传）
     * 第三步：解析设备 SDP（提取 transport、setup、ip、port）
     * 第四步：投递 broadcast_setup_rtp 任务到 Task 进程
     *   -> Task 调用 API setupBroadcastRtp（ZLM startSendRtpPassive）
     *   -> Task 检查 isStreamReady
     *   -> handleTaskFinish 回调 handleBroadcastSetupRtpResult
     *     -> 流不存在: 回复 410 Gone
     *     -> 流存在: 回复 200 OK + SDP
     *     -> broadcastPushAfterAck: 等 ACK 再推流 / 或立即推流
     *
     * 注意：
     * 1. 设备 INVITE 可能携带也可能不携带 SDP body
     * 2. INVITE 的 From URI 可能是 NVR device_id（而非 channel_id），
     *    因此所有业务字段都从 pendingBroadcast 中获取
     *
     * @param \SipEvent $event SIP INVITE 事件
     * @param string $fromDeviceId INVITE From URI 中的设备ID（可能是 NVR ID）
     * @param array $pendingBroadcast 待处理的广播会话数据
     */
    private function handleBroadcastInvite(
        \SipEvent $event,
        string $fromDeviceId,
        array $pendingBroadcast,
        ?string $sdpBody = null
    ) : void
    {
        // 从 pendingBroadcast 获取所有业务字段（不依赖 INVITE 的 From/To URI）
        $channelId = $pendingBroadcast['channel_id'];
        $deviceId = $pendingBroadcast['device_id'];
        $ssrc = $pendingBroadcast['ssrc'];
        $rtpPort = $pendingBroadcast['rtp_port'];
        $mediaServerIp = $pendingBroadcast['media_server_ip'];
        $streamId = $pendingBroadcast['stream_id'];
        $sessionId = $pendingBroadcast['session_id'] ?? null;
        $broadcastKey = $pendingBroadcast['_broadcast_key'] ?? $channelId;

        $tid = $event->getTid();
        $callId = $event->getCallId();
        $dialogId = $event->getDialogId();

        // === 第二步（WVP 对齐）：回复 100 Trying ===
        // 告知设备服务端正在处理，防止设备因超时重传 INVITE
        $this->sipServer->sendCallAnswer($tid, 100, null, 'Trying');
        $this->log("广播 INVITE: 已回复 100 Trying, fromDevice={$fromDeviceId}, channelId={$channelId}");

        // === 第三步（WVP 对齐）：解析设备 SDP ===
        // SDP Offer-Answer: 从设备 INVITE SDP 解析传输协议
        $deviceTransport = 'RTP/AVP';
        $deviceSetup = 'active';
        $deviceIp = null;
        $devicePort = null;

        if ($sdpBody) {
            $deviceSdp = \ExoSip::parseSdp($sdpBody);
            if ($deviceSdp && isset($deviceSdp['medias'][0])) {
                $deviceTransport = $deviceSdp['medias'][0]['proto'] ?? 'RTP/AVP';
                $deviceSetup = $deviceSdp['medias'][0]['attributes']['setup'] ?? 'active';
                $deviceIp = $deviceSdp['connection']['addr'] ?? null;
                $devicePort = $deviceSdp['medias'][0]['port'] ?? null;
                $this->log("设备广播SDP: transport={$deviceTransport}, setup={$deviceSetup}, ip={$deviceIp}, port={$devicePort}");
            }
        }

        $this->log("处理广播 INVITE(异步): fromDevice={$fromDeviceId}, channelId={$channelId}, deviceId={$deviceId}, sessionId={$sessionId}");

        // === 第四步（WVP 对齐）：异步调 API 开 ZLM 端口 + 检查流就绪 ===
        // 不立即发 200 OK，而是异步调 API 开 ZLM 端口
        // API 根据设备 SDP transport 决定 TCP/UDP 模式
        $taskPayload = [
            'session_id'       => $sessionId,
            'ssrc'             => $ssrc,
            'rtp_port'         => $rtpPort,
            'media_server_id'  => $pendingBroadcast['media_server_id'] ?? '',
            'device_transport' => $deviceTransport,
            'device_setup'     => $deviceSetup,
            'device_ip'        => $deviceIp,
            'device_port'      => $devicePort,
            // 传入流信息，供 API 检查流是否就绪
            'stream_id'        => $streamId,
            'app'              => $pendingBroadcast['app'] ?? 'broadcast',
        ];

        try {
            $taskId = $this->sipServer->addTask([
                'action'       => 'broadcast_setup_rtp',
                'type'         => 'broadcast_setup_rtp',
                'payload'      => $taskPayload,
                'api_hook_url' => $this->config['api_hock_url'],
            ]);

            // 存储等待结果的上下文，供 handleTaskFinish 使用
            $this->pendingInviteSetup[$taskId] = [
                'tid'               => $tid,
                'call_id'           => $callId,
                'dialog_id'         => $dialogId,
                'pending_broadcast' => $pendingBroadcast,
                'device_transport'  => $deviceTransport,
                'device_setup'      => $deviceSetup,
                'device_ip'         => $deviceIp,
                'device_port'       => $devicePort,
                'broadcast_key'     => $broadcastKey,
            ];

            $this->log("广播 INVITE: 已投递 broadcast_setup_rtp Task #{$taskId}, 等待 API 返回");

        } catch (\Exception $e) {
            $this->log("广播 INVITE: 投递 Task 失败: {$e->getMessage()}", 'ERROR');
            $this->sipServer->sendCallAnswer($tid, 500, null, 'Internal Server Error');
            $this->commandDispatcher->removePendingBroadcast($broadcastKey);
        }
    }


    /**
     * 查询设备目录
     */
    public function queryCatalog($deviceId) : bool
    {
        $deviceInfo = $this->deviceManager->getDevice($deviceId);
        if (!$deviceInfo) {
            $this->log("设备不存在: $deviceId", 'ERROR');
            return false;
        }

        // 【修正】直接使用设备实际 IP:Port 作为 To URI
        // 参考 GB28181-Service 和 SipServer.cpp 的实现
        // 不使用 realm，因为 eXosip 需要明确的网络地址才能发送
        // 优先使用注册时记录的实际源地址（received_ip），因为 Contact 可能配置错误
        $deviceIp = $deviceInfo['received_ip'] ?? $deviceInfo['ip'] ?? '127.0.0.1';
        $devicePort = $deviceInfo['received_port'] ?? $deviceInfo['port'] ?? 5060;
        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";

        if ($this->config['debug']) {
            $this->log("发送目录查询", 'DEBUG');
            $this->log("  设备 ID: {$deviceId}", 'DEBUG');
            $this->log("  目标 URI: {$targetUri}", 'DEBUG');
            $this->log("  设备地址: {$deviceIp}:{$devicePort}", 'DEBUG');
        }

        $result = $this->querySender->queryCatalog($targetUri, $deviceId);

        if ($result) {
            $this->log("✓ 目录查询已发送: $deviceId");
        } else {
            $this->log("✗ 目录查询发送失败: $deviceId", 'ERROR');
        }

        return $result;
    }

    /**
     * 查询设备信息
     */
    public function queryDeviceInfo($deviceId) : bool
    {
        $deviceInfo = $this->deviceManager->getDevice($deviceId);
        if (!$deviceInfo) {
            return false;
        }

        $deviceIp = $deviceInfo['ip'] ?? '127.0.0.1';
        $devicePort = $deviceInfo['port'] ?? 5060;
        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";

        $this->log("发送设备信息查询: $deviceId");
        return $this->querySender->queryDeviceInfo($targetUri, $deviceId);
    }

    /**
     * PTZ控制
     */
    public function ptzControl($deviceId, $channelId, $command) : bool
    {
        $deviceInfo = $this->deviceManager->getDevice($deviceId);
        if (!$deviceInfo) {
            return false;
        }

        $deviceIp = $deviceInfo['ip'] ?? '127.0.0.1';
        $devicePort = $deviceInfo['port'] ?? 5060;
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";

        $this->log("发送PTZ控制: $deviceId -> $channelId");
        return $this->querySender->ptzControl($targetUri, $channelId, $command);
    }

    // ========== 工具方法 ==========

    /**
     * 从URI中提取设备ID
     */
    private function extractDeviceId($uri)
    {
        if (preg_match('/sip:(\d{20})@/', $uri, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * 验证设备ID格式（20位数字）
     */
    private function isValidDeviceId($deviceId) : bool|int
    {
        return preg_match('/^\d{20}$/', $deviceId);
    }

    /**
     * 获取在线设备列表
     */
    public function getOnlineDevices() : array
    {
        return $this->deviceManager->getOnlineDevices();
    }

    /**
     * 获取统计信息
     */
    public function getStats() : array
    {
        $managerStats = $this->deviceManager->getStats();
        $allDevices = $this->deviceManager->getAllDevices();
        $totalDevices = count($allDevices);

        return [
            'total_devices'        => $totalDevices,
            'online_devices'       => $managerStats['online'] ?? 0,
            'unregistered_devices' => $managerStats['unregistered'] ?? 0,
            'timeout_devices'      => $managerStats['timeout'] ?? 0,
        ];
    }

    /**
     * 获取设备管理器（用于心跳超时检测）
     */
    public function getDeviceManager() : DeviceManager
    {
        return $this->deviceManager;
    }

    /**
     * 处理超时检测（应在主循环中定期调用）
     * @return array 超时的设备列表
     */
    public function processTimeouts() : array
    {
        return $this->deviceManager->checkTimeout();
    }

    /**
     * 获取所有设备信息
     */
    public function getAllDevices() : array
    {
        return $this->deviceManager->getAllDevices();
    }

    /**
     * 获取指定设备信息
     */
    public function getDevice(string $deviceId) : ?array
    {
        return $this->deviceManager->getDevice($deviceId);
    }

    /**
     * 规范化XML编码
     *
     * GB28181设备常见问题：XML声明UTF-8，实际内容GB2312/GBK
     * 解决方案：
     * 1. 检测实际编码（通过乱码特征判断）
     * 2. 转换为UTF-8
     * 3. 修正XML声明
     *
     * @param string $xml 原始XML字符串
     * @param string $deviceSpecifiedCharset 设备指定的编码
     * @return string 规范化后的UTF-8 XML
     */
    private function normalizeXmlEncoding(string $xml, string $deviceCharset = 'auto') : string
    {
        $rawXml = $xml;

        // 去BOM
        $xml = preg_replace("/^\xEF\xBB\xBF/", '', $xml);

        // 提取声明的编码
        $declaredEncoding = null;
        if (preg_match('/<\?xml[^>]+encoding=["\']([^"\']+)["\']/', $xml, $m)) {
            $declaredEncoding = strtoupper($m[1]);
        }

        // 去除声明以便体验 UTF-8 校验
        $xmlWithoutHeader = preg_replace('/<\?xml.*?\?>/i', '', $xml);

        // ① 检查：实际是否UTF-8？
        $isUtf8 = $this->isReallyUtf8($xmlWithoutHeader);

        // ② 检查：声明与实际是否一致？
        if ($declaredEncoding === 'UTF-8' && $isUtf8) {
            return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlWithoutHeader;
        }

        // ③ 声明是 GB2312/GBK 但实际 UTF-8 → 设备错误
        if (in_array($declaredEncoding, ['GB2312', 'GBK', 'GB18030']) && $isUtf8) {
            // 强制 UTF-8
            return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlWithoutHeader;
        }

        // ④ 声明 GB2312/GBK 且不是 UTF-8 → 转换
        if (in_array($declaredEncoding, ['GB2312', 'GBK', 'GB18030']) && !$isUtf8) {
            $converted = mb_convert_encoding($xmlWithoutHeader, 'UTF-8', $declaredEncoding);

            return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $converted;
        }

        // ⑤ auto 模式再尝试猜测
        if ($deviceCharset === 'auto') {
            $detected = mb_detect_encoding($xmlWithoutHeader, ['GBK', 'GB2312', 'GB18030', 'UTF-8'], true);

            if ($detected && $detected !== 'UTF-8') {
                $xmlWithoutHeader = mb_convert_encoding($xmlWithoutHeader, 'UTF-8', $detected);
            }

            return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlWithoutHeader;
        }

        // ⑥ 最后的 fallback：假设UTF-8
        if (!$isUtf8) {
            // 防止抛错，用 iconv 忽略非法字符
            $xmlWithoutHeader = @iconv($deviceCharset, 'UTF-8//IGNORE', $xmlWithoutHeader);
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xmlWithoutHeader;
    }


    private function isReallyUtf8(string $str) : bool
    {
        return (bool)preg_match('//u', $str);
    }


    // ========== Task 异步处理 ==========

    /**
     * 投递异步任务到 Task 进程
     */
    private function postTask(string $type, array $payload) : void
    {
        // 检查是否支持 addTask 方法（多进程模式）
        if (!method_exists($this->sipServer, 'addTask')) {
            // 单进程模式，直接同步处理
            $this->log("单进程模式，同步处理任务: $type", 'DEBUG');
            return;
        }

        !isset($payload['timestamp']) && $payload['timestamp'] = time();

        // 注入 gateway_id 到 payload（集群模式）
        $gatewayId = $this->config['gateway_id'] ?? null;
        if ($gatewayId && !isset($payload['gateway_id'])) {
            $payload['gateway_id'] = $gatewayId;
        }

        try {
            $taskId = $this->sipServer->addTask([
                'type'    => $type,
                'payload' => $payload,
            ]);

            if ($this->config['debug']) {
                $this->log("投递任务 #{$taskId}: $type", 'DEBUG');
            }
        } catch (\Exception $e) {
            $this->log("投递任务失败: {$e->getMessage()}", 'ERROR');
        }
    }

    /**
     * 投递 startSendRtp 任务到 Task 进程
     *
     * 广播模式下，通知 ZLM 开始向设备推送 RTP 音频流。
     * 调用时机：
     * 1. broadcastPushAfterAck=true: 收到设备 ACK 后调用（handleAck 触发）
     * 2. broadcastPushAfterAck=false 或 TCP 主动模式: 发送 200 OK 后立即调用
     *
     * @param array $info 推流信息，包含 session_id, ssrc, stream_id, app, media_server_id 等
     */
    private function dispatchStartSendRtp(array $info) : void
    {
        $this->log("投递 start_send_rtp 任务: sessionId={$info['session_id']}, streamId={$info['stream_id']}");

        try {
            $this->sipServer->addTask([
                'action'       => 'start_send_rtp',
                'type'         => 'start_send_rtp',
                'payload'      => $info,
                'api_hook_url' => $this->config['api_hock_url'],
            ]);
        } catch (\Exception $e) {
            $this->log("投递 start_send_rtp 任务失败: {$e->getMessage()}", 'ERROR');
        }
    }


    /**
     * 判断是否为注销请求
     */
    private function isUnregisterRequest(\SipEvent $event) : bool
    {
        return $event->getExpires() === 0;
    }

    /**
     * 检查是否包含 Authorization 头（包括 Capability 和 Digest）
     */
    private function hasAuthorizationHeader(\SipEvent $event) : bool
    {
        // 尝试不同的大小写变体
        $authHeader = $event->getHeader('Authorization')
            ? : $event->getHeader('authorization')
                ? : $event->getHeader('AUTHORIZATION');

        if (!$authHeader) {
            // 调试：打印所有可用的头
            // echo "[DEBUG] 没有找到 Authorization 头，CSeq: " . $event->getHeader('CSeq') . "\n";
            return false;
        }

        // echo "[DEBUG] 找到 Authorization 头: " . substr($authHeader, 0, 50) . "...\n";

        // Capability 表示声明能力，也需要返回 401
        // 只有 Digest 才是真正的认证
        if (stripos($authHeader, 'Capability') === 0) {
            return false;  // 视为首次注册
        }

        return true;
    }

    /**
     * 验证 Authorization 头
     */
    private function validateAuthorization(\SipEvent $event, string $deviceId) : bool
    {
        $authHeader = $event->getHeader('Authorization');
        if (!$authHeader) {
            return false;
        }

        // 判断认证类型
        // GB28181标准：只有 Digest 是真正的认证
        // Capability 只是声明能力，不是认证
        if (stripos($authHeader, 'Digest') === 0) {
            return $this->validateDigestAuth($authHeader, $event, $deviceId);
        }

        $this->log("不支持的认证类型: {$authHeader}", 'WARNING');
        return false;
    }

    /**
     * 验证 Digest MD5 认证
     */
    private function validateDigestAuth(string $authHeader, \SipEvent $event, string $deviceId) : bool
    {
        // 解析 Digest 认证头
        $params = $this->parseDigestAuth($authHeader);

        $this->log("解析的认证参数: " . json_encode($params, JSON_UNESCAPED_UNICODE), 'DEBUG');

        if (!isset($params['response']) || !isset($params['nonce'])) {
            $this->log("缺少必要参数: response 或 nonce", 'DEBUG');
            return false;
        }

        // 获取设备密码（从配置或数据库）
        $password = $this->getDevicePassword($deviceId);
        $realm = $this->config['server_domain'];
        $method = 'REGISTER';
        $uri = $params['uri'] ?? $event->getRequestUri() ?? "sip:{$this->config['server_id']}@{$realm}";

        // echo "[DEBUG] 认证计算参数:\n";
        // echo "  username: {$deviceId}\n";
        // echo "  realm: {$realm}\n";
        // echo "  password: {$password}\n";
        // echo "  method: {$method}\n";
        // echo "  uri: {$uri}\n";
        // echo "  nonce: {$params['nonce']}\n";

        // 计算期望的 response
        $ha1 = md5("{$deviceId}:{$realm}:{$password}");
        $ha2 = md5("{$method}:{$uri}");
        $expectedResponse = md5("{$ha1}:{$params['nonce']}:{$ha2}");

        // echo "  HA1 原文: {$deviceId}:{$realm}:{$password}\n";
        // echo "  HA1: {$ha1}\n";
        // echo "  HA2 原文: {$method}:{$uri}\n";
        // echo "  HA2: {$ha2}\n";
        // echo "  Response 原文: {$ha1}:{$params['nonce']}:{$ha2}\n";
        // echo "  期望 response: {$expectedResponse}\n";
        // echo "  实际 response: {$params['response']}\n";

        // 验证结果
        $isValid = ($expectedResponse === $params['response']);
        // echo "  验证结果: " . ($isValid ? '成功' : '失败') . "\n";

        if (!$isValid && ($this->config['debug'] ?? false)) {
            $this->log("提示: 检查设备密码配置是否正确", 'WARNING');
        }

        return $isValid;
    }

    /**
     * 从 User-Agent 获取设备安全能力
     * GB28181: 如果UA中包含安全信息（如RSA、SHA-256），则支持数字证书认证
     * 否则使用基本的 Digest MD5 认证
     */
    private function getDeviceCapability(\SipEvent $event) : ?string
    {
        $userAgent = $event->getHeader('User-Agent');
        if (!$userAgent) {
            return null;
        }

        //        echo "[DEBUG] User-Agent: {$userAgent}\n";

        // 检查是否包含安全能力关键字
        // 例如: "GB28181 Security/RSA,SHA-256,3DES"
        if (preg_match('/Security\/([^\s;]+)/', $userAgent, $matches)) {
            return $matches[1];  // 例如: "RSA,SHA-256,3DES"
        }

        // 检查是否直接包含 RSA、SHA-256 等关键字
        if (stripos($userAgent, 'RSA') !== false ||
            stripos($userAgent, 'SHA-256') !== false ||
            stripos($userAgent, 'Certificate') !== false) {
            return 'Certificate';  // 标记支持证书认证
        }

        return null;  // 不支持，使用基本认证
    }

    /**
     * 解析 Digest 认证头
     */
    private function parseDigestAuth(string $authHeader) : array
    {
        $params = [];

        //        echo "[DEBUG] 原始 Authorization 头: {$authHeader}\n";

        // 移除 "Digest " 前缀
        $authHeader = trim(substr($authHeader, 7));

        //        echo "[DEBUG] 去除前缀后: {$authHeader}\n";

        // 修复：osip 库返回的值中引号被转义为 ""，需要先还原
        $authHeader = str_replace('""', '"', $authHeader);

        //        echo "[DEBUG] 还原引号后: {$authHeader}\n";

        // 解析参数 - 支持带引号和不带引号的值
        if (preg_match_all('/(\w+)=(?:"([^"]+)"|([^,\s]+))/', $authHeader, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = $match[1];
                $value = $match[2] !== '' ? $match[2] : $match[3];
                $params[$key] = trim($value);
            }
        }

        return $params;
    }


    /**
     * 生成 nonce 值
     */
    private function generateNonce() : string
    {
        return md5(uniqid() . time() . rand());
    }


    /**
     * 获取设备密码
     * GB28181标准：服务器端使用统一的接入密码
     * 所有设备在NVR/IPC的"国标配置"中填写相同的密码
     */
    private function getDevicePassword(?string $deviceId = null) : string
    {
        // 返回统一的接入密码
        return $this->config['device_password'] ?? '12345678';
    }


    /**
     * 处理语音对讲会话已建立
     *
     * 当收到语音对讲 INVITE 的 200 OK 响应时调用。
     * 注意：ACK 已在 handleInviteResponse() 中发送，此方法只负责业务逻辑处理。
     *
     * 会话信息来自 CommandDispatcher 的 activeSessions（内存），
     * 而非 Redis。这解决了之前 Redis voice_session 键从未写入的问题。
     *
     * @param array $activeSession CommandDispatcher 中的活跃会话信息
     * @param int $dialogId SIP Dialog ID
     * @param string|null $deviceIp 设备音频接收IP（来自 200 OK 的 SDP）
     * @param int|null $devicePort 设备音频接收端口（来自 200 OK 的 SDP）
     * @return void
     */
    private function handleVoiceTalkEstablished(array $activeSession, int $dialogId, ?string $deviceIp, ?int $devicePort) : void
    {
        $deviceId = $activeSession['device_id'];
        $channelId = $activeSession['channel_id'];
        $mode = $activeSession['mode'] ?? 'talk';
        $ssrc = $activeSession['ssrc'] ?? '';
        $callId = $activeSession['call_id'] ?? 0;
        $streamId = $activeSession['stream_id'] ?? '';

        // ACK 已在 handleInviteResponse() 中统一发送，此处不再重复发送

        // 通知 API 会话已建立（voice_established 回调）
        // API 侧的 GBServerHookController 将调用 VoiceTalkService::onSipResponseOk()
        // 来更新数据库中的会话状态为 CONNECTED
        $this->postTask('voice_established', [
            'device_id'   => $deviceId,
            'channel_id'  => $channelId,
            'dialog_id'   => $dialogId,
            'call_id'     => $callId,
            'device_ip'   => $deviceIp,
            'device_port' => $devicePort,
            'mode'        => $mode,
            'ssrc'        => $ssrc,
            'stream_id'   => $streamId,
            'session_id'  => $activeSession['session_id'] ?? null,
            'timestamp'   => time(),
        ]);

        $this->log("语音对讲会话已建立: {$deviceId}/{$channelId}, Dialog-ID: {$dialogId}, Stream: {$streamId}");
    }


    /**
     * 日志输出
     */
    private function log(string $message, string $level = 'INFO') : void
    {
        $this->logger->log($message, $level, 'GB28181');
    }
}
