<?php

namespace Gb28181\GateWay\Handlers;

use \ExoSip;
use Gb28181\GateWay\Device\DeviceManager;
use Gb28181\GateWay\Handlers\LongTask\RedisSubscriber;
use Gb28181\GateWay\Message\CommandDispatcher;
use Gb28181\GateWay\Message\MessageHandler;
use Gb28181\GateWay\Message\QuerySender;
use Gb28181\GateWay\Message\CommandType\KeepaliveCommand;
use Gb28181\GateWay\Message\CommandType\CatalogCommand;
use Gb28181\GateWay\Message\CommandType\DeviceInfoCommand;
use Gb28181\GateWay\Message\CommandType\DeviceStatusCommand;
use Gb28181\GateWay\Message\CommandType\AlarmCommand;
use Gb28181\GateWay\Message\CommandType\MobilePositionCommand;
use Gb28181\GateWay\Message\CommandType\MediaStatusCommand;
use Gb28181\GateWay\Traits\CurlTrait;
use Gb28181\GateWay\Traits\SIPMessageHandleTrait;
use Gb28181\GateWay\Wrappers\CallbackWrapper;
use Gb28181\GateWay\Libs\Logger;

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
    use CurlTrait, SIPMessageHandleTrait;

    private ExoSip $sipServer;
    private array $config;
    private ?DeviceManager $deviceManager = null;
    private ?MessageHandler $messageHandler = null;
    private ?QuerySender $querySender = null;
    private ?CommandDispatcher $commandDispatcher = null;
    private Logger $logger;

    /**
     * 构造函数
     * @param ExoSip $sipServer SIP服务器实例
     * @param array $config 配置参数
     */
    public function __construct(ExoSip $sipServer, array $config = [])
    {
        $this->sipServer = $sipServer;

        $this->config = array_merge([
            'heartbeat_timeout' => 180,
            'check_interval' => 30,
            'register_expires' => 3600,
            'catalog_auto_query' => false,
        ], $config);

        // 初始化日志
        $this->logger = Logger::getInstance([
            'log_file' => $config['log_file'] ?? 'php://stdout',
            'min_level' => $config['debug'] ? 'DEBUG' : 'INFO',
        ]);

        // 初始化设备管理器
        $this->deviceManager = new DeviceManager(
            $this->config['heartbeat_timeout'],
            $this->config['check_interval'],
            [
                'cache_file' => $this->config['device_cache_file'] ?? '/tmp/gb28181_devices.cache',
                'api_loader' => [$this, 'deviceManagerListener']
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

        // 初始化查询发送器
        $this->querySender = new QuerySender($sipServer, [
            'server_id' => $this->config['server_id'],
            'server_domain' => $this->config['server_domain'],
            'debug' => $this->config['debug'] ?? false,
        ]);

        // 初始化命令分发器
        $this->commandDispatcher = new CommandDispatcher(
            $sipServer,
            $this->querySender,
            $this->deviceManager,
            array_merge([
                'debug' => $this->config['debug'] ?? false,
                'server_id' => $this->config['server_id'],
            ], $config['zlm'])
        );

        $this->log("GB28181 协议处理器已初始化");
    }

    /**
     * 绑定事件处理器到SIP服务器
     */
    public function bindEvents(): void
    {
        // 核心SIP方法事件

        $this->sipServer->onWorkerStart = CallbackWrapper::wrap($this, 'handleWorkerStart');
        // 绑定 onPipeMessage (接收Task的推送)
        $this->sipServer->onPipeMessage = CallbackWrapper::wrap($this, 'handleOnPipeMessage');
        $this->sipServer->onRegister = CallbackWrapper::wrap($this, 'handleRegister'); //[$this, 'handleRegister'];
        $this->sipServer->onMessage = CallbackWrapper::wrap($this, 'handleMessage');//[$this, 'handleMessage'];
        $this->sipServer->onInvite = CallbackWrapper::wrap($this, 'handleInvite');//[$this, 'handleInvite'];
        $this->sipServer->onBye = CallbackWrapper::wrap($this, 'handleBye');//[$this, 'handleBye'];
        $this->sipServer->onAck = CallbackWrapper::wrap($this, 'handleAck');//[$this, 'handleAck'];

        // SIP扩展方法
        $this->sipServer->onInfo = CallbackWrapper::wrap($this, 'handleInfo');//[$this, 'handleInfo'];           // INFO消息（PTZ控制等）
        $this->sipServer->onUpdate = CallbackWrapper::wrap($this, 'handleUpdate');//[$this, 'handleUpdate'];       // UPDATE请求
        $this->sipServer->onRefer = CallbackWrapper::wrap($this, 'handleRefer');//[$this, 'handleRefer'];         // REFER转接

        // Publish-Subscribe（订阅/通知机制）
        $this->sipServer->onSubscribe = CallbackWrapper::wrap($this, 'handleSubscribe');//[$this, 'handleSubscribe']; // 订阅请求
        $this->sipServer->onNotify = CallbackWrapper::wrap($this, 'handleNotify');//[$this, 'handleNotify'];       // 通知消息

        // 响应和错误处理
        $this->sipServer->onResponse = CallbackWrapper::wrap($this, 'handleResponse');//[$this, 'handleResponse'];   // 响应事件
        $this->sipServer->onTimeout = CallbackWrapper::wrap($this, 'handleTimeout');//[$this, 'handleTimeout'];     // 超时事件
        $this->sipServer->onError = CallbackWrapper::wrap($this, 'handleError');// [$this, 'handleError'];         // 错误事件

        // 可选的其他事件
        // $this->sipServer->onCancel = [$this, 'handleCancel'];
        // $this->sipServer->onOptions = [$this, 'handleOptions'];
        // $this->sipServer->onPrack = [$this, 'handlePrack'];
        // $this->sipServer->onPublish = [$this, 'handlePublish'];
        //

        $this->sipServer->onTimer = CallbackWrapper::wrap($this, 'tick');//[$this, 'tick'];                // 底层定时器，主要用于处理设备心跳超时和离线设备清理

        $this->sipServer->onTask = CallbackWrapper::wrap($this, 'handleTask');//[$this, 'handleTask'];                // task接收

        $this->sipServer->onTaskFinish = CallbackWrapper::wrap($this, 'handleTaskFinish');//[$this, 'handleTaskFinish'];      // task执行完成
    }

    /**
     * 设备管理器监听器
     * @return array|null
     */
    public function deviceManagerListener(): ?array
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
    public function handleWorkerStart(ExoSip $server): void
    {
        $this->log("Worker started (PID: " . posix_getpid() . ")");

        //  捕获需要的变量到闭包
        $config = $this->config;
        // 启动 Redis 订阅器 Long Task
        $server->startLongTask(function () use ($server, $config) {
            $this->log("[LongTask] Redis Subscriber started (PID: " . getmypid() . ")");

            // 使用封装的 RedisSubscriber 类
            $subscriber = new RedisSubscriber(
                $config['redis'],
                $config['debug'] ?? false
            );

            $subscriber->run($server, $config['redis']['queue_name'], 0);
        });
    }

    public function handleOnPipeMessage(array $message): void
    {
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

        // TODO: 将结果推送到 Redis 或回调接口
        if (!$result['success']) {
            $this->log("Command failed: {$result['error']}", 'ERROR');
        }
    }

    /**
     * 定时任务处理（在主循环中调用）
     */
    public function tick(): void
    {
        static $lastCheckTime = 0;
        static $lastCleanupTime = 0;

        $now = time();

        // 检查设备心跳超时
//        $this->log("Checking device heartbeat timeout:{$lastCheckTime}-{$this->config['check_interval']}");
        if ($now - $lastCheckTime >= $this->config['check_interval']) {
            $timeoutDevices = $this->deviceManager->checkTimeout();
            $lastCheckTime = $now;
            $this->log("Checking device heartbeat timeout:{$lastCheckTime}");

            // 通知 API 更新超时设备状态为 expired
            if (!empty($timeoutDevices)) {
                $this->log("发现 " . count($timeoutDevices) . " 个心跳超时设备", 'WARNING');
                foreach ($timeoutDevices as $device) {
//                    $device = $this->deviceManager->getDevice($deviceId);
                    $this->postTask('device_expired', [
                        'device_id' => $device['device_id'],
                        'last_heartbeat' => $device['last_heartbeat'] ?? 0,
                        'timeout' => $this->config['heartbeat_timeout'],
                        'timestamp' => $now,
                    ]);
                    $this->log("设备心跳超时: {$device['device_id']}", 'WARNING');
                }
            }
        }

        // TODO: 清理离线设备
        $cleanupInterval = $this->config['check_offline_device_interval'] ?? 3600;
        if ($now - $lastCleanupTime >= $cleanupInterval) {
            $this->deviceManager->cleanupOfflineDevices();
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
        }
    }

    /**
     * 任务处理
     * @param $taskId
     * @param $taskData
     */
    public function handleTask($taskId, $taskData): array
    {
        $this->log("Task #{$taskId} processing", 'DEBUG');
        if (empty($taskData) || !isset($taskData['type'])) {
            return [
                'success' => false,
                'error' => 'Invalid task data',
            ];
        }

        $this->curlPost($this->config['api_hock_url'], [
            'scene' => $taskData['type'],
            'body' => $taskData['payload'] ?? [], // 替换为你要发送的实际数据
        ]);

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
    public function handleTaskFinish($taskId, $result): void
    {
        $this->log("Task #{$taskId} finished", 'DEBUG');
        if (isset($result['success']) && !$result['success']) {
            $this->log("Task #{$taskId} failed: {$result['error']}", 'ERROR');
        }
    }

    /**
     * 处理设备注册（包括注销）
     */
    public function handleRegister(\SipEvent $event): void
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
                'Expires' => 0
            ]);

            // 通知 API 更新状态为 unregistered
            $this->postTask('device_unregister', [
                'device_id' => $deviceId,
                'registered_at' => $device['registered_at'] ?? 0,
                'last_heartbeat' => $device['last_heartbeat'] ?? 0,
                'expires' => 0,
                'timestamp' => time(),
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
                'WWW-Authenticate' => "Digest realm=\"{$realm}\", nonce=\"{$nonce}\", algorithm=MD5"
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
        $finalIp = $receivedIp ?: $contactIp;
        $finalPort = $receivedPort ?: $contactPort;

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
            'uri' => $fromUri,
            'device_id' => $deviceId,
            'ip' => $finalIp,
            'port' => $finalPort,
            'user_agent' => $event->getHeader('User-Agent'),
            'received_ip' => $receivedIp,  // 保存实际源地址，供调试使用
            'received_port' => $receivedPort,
            'registered_at' => time(),
            'timestamp' => time(),
            'expires' => $this->config['register_expires']
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
            'Expires' => $this->config['register_expires']
        ]);

        $stats = $this->deviceManager->getStats();
        $this->log("当前在线设备: {$stats['online']}");

        $this->postTask('register', [
            'device_id' => $deviceId,
            'from_uri' => $fromUri,
            'ip' => $finalIp,
            'port' => $finalPort,
            'user_agent' => $event->getHeader('User-Agent'),
            'received_ip' => $receivedIp,  // 保存实际源地址，供调试使用
            'received_port' => $receivedPort,
            'registered_at' => time(),
            'timestamp' => time(),
            'expires' => $this->config['register_expires']
        ]);


        // 自动查询设备目录
        if ($this->config['catalog_auto_query']) {
            $this->queryCatalog($deviceId);
        }
    }


    /**
     * 处理SIP MESSAGE（GB28181 XML消息）
     */
    public function handleMessage(\SipEvent $event): void
    {
        $body = $event->getBody();
        $fromUri = $event->getFromUri();
        $deviceId = $this->extractDeviceId($fromUri);

        // 检查 body 是否为空
        if (empty($body)) {
            $this->log("收到空消息体，忽略", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
            return;
        }

        // GB28181 编码兼容处理
        // 问题：设备声明 UTF-8 但实际发送 GB2312/GBK 编码
        // 解决：检测实际编码并转换为 UTF-8
        $body = $this->normalizeXmlEncoding($body);

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

        try {
            // 使用 MessageHandler 处理消息
            $result = $this->messageHandler->handle($xml, $deviceId, [
                'event' => $event,
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
     * 区分两种场景:
     * 1. 语音对讲(Broadcast/Talk): 设备主动发起INVITE,Subject包含broadcast或talk
     * 2. 视频点播: 服务器主动发起INVITE(由CommandDispatcher处理)
     */
    public function handleInvite(\SipEvent $event): void
    {
        $fromUri = $event->getFromUri();
        $toUri = $event->getToUri();
        $deviceId = $this->extractDeviceId($fromUri);
        $channelId = $this->extractDeviceId($toUri);
        $subject = $event->getHeader('Subject') ?? '';
        $body = $event->getBody();

        $this->log("收到INVITE: 设备{$deviceId} 通道{$channelId}");

        // 检查设备是否在线
        $device = $this->deviceManager->getDevice($deviceId);
        if (!$device || !$device['registered']) {
            $this->log("设备未注册: {$deviceId}", 'ERROR');
            $this->sipServer->sendResponse($event->getTid(), 404, 'Not Found');
            return;
        }

        // 判断是否为语音对讲请求
        $isBroadcast = stripos($subject, 'broadcast') !== false;
        $isTalk = stripos($subject, 'talk') !== false;

        if ($isBroadcast || $isTalk) {
            $this->handleVoiceInvite($event, $deviceId, $channelId, $body, $isBroadcast ? 'broadcast' : 'talk');
        } else {
            // 常规视频INVITE(目前简化处理)
            $this->log("视频INVITE: {$deviceId} -> {$channelId}");
            $this->sipServer->sendResponse($event->getTid(), 180, 'Ringing');
            $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
            $this->log("视频会话已建立");
        }
    }

    /**
     * 处理会话结束BYE
     */
    public function handleBye(\SipEvent $event): void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $callId = $event->getCallId();

        $this->log("收到 BYE: $deviceId (Call-ID: $callId)");

        // 通知外部系统会话结束
        $this->postTask('session_bye', [
            'device_id' => $deviceId,
            'call_id' => $callId,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理ACK
     */
    public function handleAck(\SipEvent $event): void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $this->log("ACK确认: {$deviceId}", 'DEBUG');
    }

    /**
     * 处理INFO（PTZ控制等）
     */
    public function handleInfo(\SipEvent $event): void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $this->log("INFO消息: {$deviceId}", 'DEBUG');
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理UPDATE请求
     */
    public function handleUpdate(\SipEvent $event): void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $this->log("UPDATE请求: {$deviceId}", 'DEBUG');

        // UPDATE通常用于会话参数更新（如媒体参数）
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理REFER转接
     */
    public function handleRefer(\SipEvent $event): void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $this->log("REFER转接: {$deviceId}", 'DEBUG');

        // REFER用于呼叫转移
        $this->sipServer->sendResponse($event->getTid(), 202, 'Accepted');
    }

    /**
     * 处理订阅请求（SUBSCRIBE）
     *
     * GB28181 使用 SUBSCRIBE/NOTIFY 机制实现移动设备位置订阅：
     * - Event: presence - 移动设备位置订阅
     * - Expires: 订阅时长（秒），0表示取消订阅
     *
     * 订阅流程：
     * 1. 平台发送 SUBSCRIBE（Event: presence, Expires: 3600）
     * 2. 设备回复 200 OK
     * 3. 设备周期性发送 NOTIFY（Event: presence，包含位置信息）
     * 4. 平台在过期前发送 SUBSCRIBE 刷新订阅
     * 5. 取消订阅时发送 SUBSCRIBE（Expires: 0）
     *
     * 注意事项：
     * - 如果 Expires 太小（< 设备最小值），设备可能返回 423 Interval Too Small
     * - 返回头域需包含 Min-Expires 指示最小订阅时间
     */
    public function handleSubscribe(\SipEvent $event): void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $eventType = $event->getHeader('Event') ?? 'unknown';
        $expires = $event->getExpires() ?? 3600;
        $body = $event->getBody();

        $this->log("订阅请求: {$deviceId}, Event: {$eventType}, Expires: {$expires}");

        // 处理移动设备位置订阅（Event: presence）
        if ($eventType === 'presence') {
            $this->handleMobilePositionSubscribe($event, $deviceId, $expires, $body);
        } else {
            // 其他订阅类型（目录订阅等）
            $this->log("未处理的订阅类型: {$eventType}", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
                'Expires' => $expires
            ]);
        }
    }

    /**
     * 处理通知消息（NOTIFY）
     *
     * NOTIFY 用于异步通知，设备主动向平台发送状态信息。
     *
     * 两种 NOTIFY 类型：
     *
     * 1. 订阅事件通知（通过 Event 头域判断）：
     *    - Event: presence - 移动设备位置订阅通知
     *    - 需要检查 Subscription-State 头域（active/pending/terminated）
     *    - XML Body 包含位置信息（MobilePosition）
     *
     * 2. XML 命令通知（通过 CmdType 判断）：
     *    - MediaStatus: GB28181-2022 媒体状态通知（截图完成/流保活）
     *    - 其他自定义命令类型
     *
     * 处理流程：
     * 1. 优先检查 Event 头域（订阅事件）
     * 2. 如果没有 Event 或不识别，解析 XML CmdType（命令通知）
     * 3. 通过 MessageHandler 分发到对应的 CommandType 处理
     */
    public function handleNotify(\SipEvent $event): void
    {
        $deviceId = $this->extractDeviceId($event->getFromUri());
        $eventType = $event->getHeader('Event') ?? '';
        $subscriptionState = $event->getHeader('Subscription-State') ?? '';
        $body = $event->getBody();

        $this->log("通知消息: {$deviceId}, Event: {$eventType}, State: {$subscriptionState}", 'DEBUG');

        // 优先处理订阅事件通知（Event 头域）
        if ($eventType === 'presence') {
            // 移动设备位置订阅通知
            $this->handleMobilePositionNotify($event, $deviceId, $subscriptionState, $body);
            return;
        }

        if ($body && $this->config['debug'] ?? false) {
            $this->log("NOTIFY Body: {$body}", 'DEBUG');
        }

        // 处理 XML 命令通知（CmdType）
        if ($body) {
            // 规范化编码
            $body = $this->normalizeXmlEncoding($body);

            // 解析 XML
            $xml = @simplexml_load_string($body);
            if ($xml) {
                try {
                    // 使用 MessageHandler 统一处理（和 handleMessage 相同的模式）
                    $result = $this->messageHandler->handle($xml, $deviceId, [
                        'event' => $event,
                        'device_manager' => $this->deviceManager,
                    ]);

                    $cmdType = $result['cmd_type'] ?? 'Unknown';
                    $this->log("收到 NOTIFY: $deviceId -> $cmdType");

                    // 分发命令结果
                    $this->dispatchCommand($event, $deviceId, $result);
                    return;

                } catch (\InvalidArgumentException $e) {
                    // 未知的命令类型，记录日志但不报错
                    $this->log("未知 NOTIFY 命令: " . $e->getMessage(), 'WARNING');
                }
            }
        }

        // 处理通知内容（可能是设备状态变化等）
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理 MediaStatus 通知 (GB28181-2022)
     *
     * 处理两种通知类型：
     * - SnapshotComplete: 图像抓拍完成通知
     * - Keepalive: 媒体流心跳通知
     */
    private function handleMediaStatusReport(\SipEvent $event, string $deviceId, array $result): void
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
                'device_id' => $deviceId,
                'session_id' => $sessionId,
                'file_url' => $fileUrl,
                'notify_type' => 'SnapshotComplete',
                'timestamp' => time()
            ]);
        } elseif ($notifyType === 'Keepalive') {
            // 媒体流心跳通知
            $ssrc = $result['ssrc'] ?? '';
            $bitRate = $result['bit_rate'] ?? '';
            $frameRate = $result['frame_rate'] ?? '';
            $packetLoss = $result['packet_loss'] ?? '';

            $this->log("媒体流心跳: SSRC={$ssrc}, BitRate={$bitRate}, FrameRate={$frameRate}, Loss={$packetLoss}", 'DEBUG');

            // 可选: 推送媒体流状态到业务系统
            $this->postTask('media_status', [
                'device_id' => $deviceId,
                'ssrc' => $ssrc,
                'bit_rate' => $bitRate,
                'frame_rate' => $frameRate,
                'packet_loss' => $packetLoss,
                'notify_type' => 'Keepalive',
                'timestamp' => time()
            ]);
        }

        // 发送 200 OK
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理移动设备位置订阅（SUBSCRIBE）
     *
     * 订阅流程：
     * 1. 平台发送 SUBSCRIBE（Event: presence, Expires: 3600）
     * 2. 检查 Expires 值（需要 > 0 且 < 3600）
     * 3. 如果 Expires 太小，返回 423 Interval Too Small + Min-Expires
     * 4. 保存订阅信息（设备ID、过期时间、CallID等）
     * 5. 返回 200 OK，等待设备发送 NOTIFY
     *
     * @param \SipEvent $event SUBSCRIBE 事件
     * @param string $deviceId 设备ID
     * @param int $expires 订阅时长（秒）
     * @param string $body 消息体（可能包含订阅参数）
     */
    private function handleMobilePositionSubscribe(\SipEvent $event, string $deviceId, int $expires, string $body): void
    {
        $callId = $event->getCallId();
        $minExpires = $this->config['mobile_position_min_expires'] ?? 60; // 最小订阅时间（秒）
        $maxExpires = $this->config['mobile_position_max_expires'] ?? 3600; // 最大订阅时间（秒）

        $this->log("位置订阅请求: {$deviceId}, Expires: {$expires}");

        // 取消订阅（Expires = 0）
        if ($expires === 0) {
            $this->log("取消位置订阅: {$deviceId}");

            // 删除订阅记录
            $this->deviceManager->removeSubscription($deviceId, 'mobile_position');

            // 通知业务系统
            $this->postTask('mobile_position_unsubscribe', [
                'device_id' => $deviceId,
                'call_id' => $callId,
                'timestamp' => time(),
            ]);

            $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
                'Expires' => 0
            ]);
            return;
        }

        // 检查订阅时间是否太小
        if ($expires > 0 && $expires < $minExpires) {
            $this->log("订阅时间太短: {$expires}s < {$minExpires}s (最小值)", 'WARNING');

            $this->sipServer->sendResponse($event->getTid(), 423, 'Interval Too Small', [
                'Min-Expires' => $minExpires
            ]);
            return;
        }

        // 限制最大订阅时间
        if ($expires > $maxExpires) {
            $expires = $maxExpires;
            $this->log("订阅时间超过最大值，限制为: {$maxExpires}s", 'WARNING');
        }

        // 解析订阅参数（如果有 XML Body）
        $interval = null; // 位置上报间隔
        if (!empty($body)) {
            $body = $this->normalizeXmlEncoding($body);
            $xml = @simplexml_load_string($body);
            if ($xml) {
                $interval = isset($xml->Interval) ? (int)$xml->Interval : null;
            }
        }

        // 保存订阅信息
        $subscription = [
            'device_id' => $deviceId,
            'type' => 'mobile_position',
            'event' => 'presence',
            'call_id' => $callId,
            'expires' => $expires,
            'expire_time' => time() + $expires,
            'interval' => $interval,
            'created_at' => time(),
        ];

        $this->deviceManager->addSubscription($deviceId, 'mobile_position', $subscription);

        // 通知业务系统
        $this->postTask('mobile_position_subscribe', [
            'device_id' => $deviceId,
            'expires' => $expires,
            'interval' => $interval,
            'call_id' => $callId,
            'timestamp' => time(),
        ]);

        // 返回 200 OK
        $this->sipServer->sendResponse($event->getTid(), 200, 'OK', [
            'Expires' => $expires
        ]);

        $this->log("位置订阅成功: {$deviceId}, 有效期: {$expires}s" . ($interval ? ", 上报间隔: {$interval}s" : ""));
    }

    /**
     * 处理移动设备位置通知（NOTIFY with Event: presence）
     *
     * 通知流程：
     * 1. 设备发送 NOTIFY（Event: presence, Subscription-State: active）
     * 2. 检查 Subscription-State（active/pending/terminated）
     * 3. 解析 XML Body 获取位置信息
     * 4. 返回 200 OK
     * 5. 如果 State = terminated，删除订阅记录
     *
     * @param \SipEvent $event NOTIFY 事件
     * @param string $deviceId 设备ID
     * @param string $subscriptionState 订阅状态（active/pending/terminated）
     * @param string $body XML 消息体（位置信息）
     */
    private function handleMobilePositionNotify(\SipEvent $event, string $deviceId, string $subscriptionState, string $body): void
    {
        $this->log("位置通知: {$deviceId}, State: {$subscriptionState}");

        // 检查订阅状态
        $isTerminated = stripos($subscriptionState, 'terminated') !== false;

        if ($isTerminated) {
            $this->log("位置订阅已终止: {$deviceId}");
            $this->deviceManager->removeSubscription($deviceId, 'mobile_position');
        }

        // 解析位置信息
        if (empty($body)) {
            $this->log("位置通知消息体为空", 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
            return;
        }

        $body = $this->normalizeXmlEncoding($body);
        $xml = @simplexml_load_string($body);

        if (!$xml) {
            $this->log("位置通知 XML 解析失败", 'ERROR');
            $this->sipServer->sendResponse($event->getTid(), 400, 'Bad Request');
            return;
        }

        try {
            // 使用 MobilePositionCommand 解析位置信息
            $result = $this->messageHandler->handle($xml, $deviceId, [
                'event' => $event,
                'device_manager' => $this->deviceManager,
            ]);

            // 提取位置数据
            $longitude = $result['longitude'] ?? 0;
            $latitude = $result['latitude'] ?? 0;
            $speed = $result['speed'] ?? 0;
            $direction = $result['direction'] ?? 0;
            $altitude = $result['altitude'] ?? 0;
            $time = $result['time'] ?? '';

            $this->log("  坐标: ({$longitude}, {$latitude}), 速度: {$speed} km/h");

            // 推送位置信息到业务系统
            $this->postTask('mobile_position', [
                'device_id' => $deviceId,
                'longitude' => $longitude,
                'latitude' => $latitude,
                'speed' => $speed,
                'direction' => $direction,
                'altitude' => $altitude,
                'time' => $time,
                'subscription_state' => $subscriptionState,
                'is_terminated' => $isTerminated,
                'timestamp' => time(),
            ]);

            $this->sipServer->sendResponse($event->getTid(), 200, 'OK');

        } catch (\InvalidArgumentException $e) {
            $this->log("未知的位置通知格式: " . $e->getMessage(), 'WARNING');
            $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
        }
    }

    /**
     * 处理位置信息上报（MESSAGE）
     *
     * 注意：MESSAGE 方式已过时，推荐使用 SUBSCRIBE/NOTIFY 订阅机制
     * 保留此方法是为了兼容旧版本设备
     */
    private function handleMobilePositionReport(\SipEvent $event, string $deviceId, array $result): void
    {
        $this->log("位置信息上报: $deviceId");

        $longitude = $result['longitude'] ?? 0;
        $latitude = $result['latitude'] ?? 0;
        $speed = $result['speed'] ?? 0;
        $direction = $result['direction'] ?? 0;
        $altitude = $result['altitude'] ?? 0;
        $time = $result['time'] ?? '';

        $this->log("  坐标: ({$longitude}, {$latitude})");
        $this->log("  速度: {$speed} km/h, 方向: {$direction}°");

        // 异步推送位置信息
        $this->postTask('mobile_position', [
            'device_id' => $deviceId,
            'longitude' => $longitude,
            'latitude' => $latitude,
            'speed' => $speed,
            'direction' => $direction,
            'altitude' => $altitude,
            'time' => $time,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }


    /**
     *  关键：处理设备对 INVITE 的 200 OK 响应（含 SDP）
     *  以及 MESSAGE 查询命令的 200 OK 响应
     */
    public function handleResponse(\SipEvent $event): void
    {
        $code = $event->getCode();
        $type = $event->getType();
        $callId = $event->getCallId();

        $this->log("收到响应: Type=$type Code=$code Call-ID=$callId");

        // 根据响应码处理
        if ($code >= 200 && $code < 300) {
            //  成功响应
            if ($code == 200) {
                // INVITE 的 200 OK（含 SDP）- type=7 是 EXOSIP_CALL_ANSWERED
                if ($type == EXOSIP_CALL_RINGING || $type == EXOSIP_CALL_ANSWERED) {
                    $this->handleInviteResponse($event);
                } // MESSAGE 的 200 OK（查询命令已接收）
                elseif ($type == EXOSIP_MESSAGE_ANSWERED || $type == EXOSIP_CALL_MESSAGE_ANSWERED) {
                    $this->handleMessageResponse($event);
                } else {
                    if ($this->config['debug'] ?? false) {
                        $this->log("请求成功: Type=$type Code=$code (未处理)", 'DEBUG');
                    }
                }
            }
        } elseif ($code >= 400) {
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
     * 处理超时事件
     */
    public function handleTimeout(\SipEvent $event): void
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
    public function handleError(string $errorMsg): void
    {
        $this->log("Event callback error: $errorMsg", 'ERROR');

        // 可以根据错误消息进行不同的处理
        // 例如：Fatal error, Exception等
    }

    // ========== 具体命令处理 ==========

    /**
     * 处理心跳保活
     */
    private function handleKeepalive(\SipEvent $event, string $deviceId, array $data): void
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

    /**
     * 处理目录响应
     */
    private function handleCatalog(\SipEvent $event, string $deviceId, array $result): void
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
        if ($device) {
            $device->setChannels($items);
            $this->log("已更新设备 {$deviceId} 的通道列表到内存", 'DEBUG');
        }

        // 异步保存目录到数据库
        $this->postTask('device_catalog', [
            'device_id' => $deviceId,
            'sum_num' => $sumNum,
            'devices' => $items,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理设备信息响应
     */
    private function handleDeviceInfo(\SipEvent $event, string $deviceId, array $result): void
    {
        $this->log("设备信息: $deviceId");

        $deviceInfo = $result['device_info'] ?? [];
        $info = [
            'name' => $deviceInfo['DeviceName'] ?? '',
            'manufacturer' => $deviceInfo['Manufacturer'] ?? '',
            'model' => $deviceInfo['Model'] ?? '',
            'firmware' => $deviceInfo['Firmware'] ?? '',
            'channel' => $deviceInfo['Channel'] ?? 0,
        ];

        $this->deviceManager->updateDeviceInfo($deviceId, ['info' => $info]);

        $this->log("  名称: {$info['name']}");
        $this->log("  厂商: {$info['manufacturer']}");

        $this->postTask('device_info', [
            'device_id' => $deviceId,
            'device_info' => $deviceInfo,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理设备状态响应
     */
    private function handleDeviceStatus(\SipEvent $event, string $deviceId, array $data): void
    {
        $this->log("设备状态: $deviceId");

        $online = $data['online'] ?? 'unregistered';
        $status = $data['status'] ?? 'OK';

        $this->log("  在线: $online, 状态: $status");

        $this->postTask('device_status', [
            'device_id' => $deviceId,
            'online' => $online,
            'status' => $status,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }

    /**
     * 处理报警信息
     */
    private function handleAlarm(\SipEvent $event, string $deviceId, array $data): void
    {
        $this->log("报警信息: $deviceId", 'WARNING');

        $alarmPriority = $data['alarm_priority'] ?? '1';
        $alarmMethod = $data['alarm_method'] ?? 'Unknown';

        $this->log("  优先级: $alarmPriority, 方式: $alarmMethod");

        // 异步推送报警信息
        $this->postTask('alarm', [
            'event' => 'alarm',
            'device_id' => $deviceId,
            'priority' => $alarmPriority,
            'method' => $alarmMethod,
            'data' => $data,
            'timestamp' => time(),
        ]);

        $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
    }


    #endregion


    /**
     * 分发命令到具体处理方法
     */
    private function dispatchCommand(\SipEvent $event, string $deviceId, array $result): void
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
            case 'DeviceStatus':
                $this->handleDeviceStatus($event, $deviceId, $result);
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
            default:
                $this->log("未处理的命令: $cmdType", 'WARNING');
                $this->sipServer->sendResponse($event->getTid(), 200, 'OK');
        }
    }

    /**
     * 处理语音对讲INVITE
     *
     * 流程:
     * 1. 设备发送INVITE(包含SDP,说明音频接收能力)
     * 2. 服务器回复200 OK(包含SDP,说明音频发送参数)
     * 3. 设备发送ACK确认
     * 4. 服务器开始推送音频流到设备
     */
    private function handleVoiceInvite(\SipEvent $event, string $deviceId, string $channelId, string $sdpBody, string $mode): void
    {
        $this->log("语音对讲INVITE: {$deviceId} 模式:{$mode}");

        //  使用原生 SDP 解析器（支持 GB28181 扩展）
        $deviceSdp = \ExoSip::parseSdp($sdpBody);
        if (!$deviceSdp) {
            $this->log("SDP解析失败", 'ERROR');
            $this->sipServer->sendResponse($event->getTid(), 400, 'Bad Request');
            return;
        }

        // 提取标准 SDP 字段
        $deviceIp = $deviceSdp['connection']['addr'] ?? null;
        $devicePort = isset($deviceSdp['medias'][0]) ? $deviceSdp['medias'][0]['port'] : null;
        $transport = isset($deviceSdp['medias'][0]) ? $deviceSdp['medias'][0]['proto'] : 'RTP/AVP';

        // 提取媒体模式（从 attributes 中查找）
        $mediaMode = 'sendrecv';  // 默认
        if (isset($deviceSdp['medias'][0]['attributes'])) {
            $attrs = $deviceSdp['medias'][0]['attributes'];
            if (isset($attrs['sendonly'])) $mediaMode = 'sendonly';
            if (isset($attrs['recvonly'])) $mediaMode = 'recvonly';
            if (isset($attrs['sendrecv'])) $mediaMode = 'sendrecv';
        }

        if (!$deviceIp || !$devicePort) {
            $this->log("设备SDP缺少IP或端口", 'ERROR');
            $this->sipServer->sendResponse($event->getTid(), 400, 'Bad Request');
            return;
        }

        $this->log("设备音频接收: {$deviceIp}:{$devicePort} (传输:{$transport}, 模式:{$mediaMode})");

        // 通知API项目处理语音对讲（API项目负责ZLM端口分配、SSRC生成、SDP构建）
        $this->postTask('voice_invite', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'mode' => $mode,
            'device_ip' => $deviceIp,
            'device_port' => $devicePort,
            'transport' => $transport,
            'media_mode' => $mediaMode,
            'tid' => $event->getTid(),
            'timestamp' => time(),
        ]);

        $this->log("语音对讲请求已转发到API项目处理");
    }


    /**
     * 查询设备目录
     */
    public function queryCatalog($deviceId): bool
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
    public function queryDeviceInfo($deviceId): bool
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
    public function ptzControl($deviceId, $channelId, $command): bool
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
    private function isValidDeviceId($deviceId): bool|int
    {
        return preg_match('/^\d{20}$/', $deviceId);
    }

    /**
     * 获取在线设备列表
     */
    public function getOnlineDevices(): array
    {
        return $this->deviceManager->getOnlineDevices();
    }

    /**
     * 获取统计信息
     */
    public function getStats(): array
    {
        $managerStats = $this->deviceManager->getStats();
        $allDevices = $this->deviceManager->getAllDevices();
        $totalDevices = count($allDevices);

        return [
            'total_devices' => $totalDevices,
            'online_devices' => $managerStats['online'] ?? 0,
            'unregistered_devices' => $managerStats['unregistered'] ?? 0,
            'timeout_devices' => $managerStats['timeout'] ?? 0,
        ];
    }

    /**
     * 获取设备管理器（用于心跳超时检测）
     */
    public function getDeviceManager(): DeviceManager
    {
        return $this->deviceManager;
    }

    /**
     * 处理超时检测（应在主循环中定期调用）
     * @return array 超时的设备列表
     */
    public function processTimeouts(): array
    {
        return $this->deviceManager->checkTimeout();
    }

    /**
     * 获取所有设备信息
     */
    public function getAllDevices(): array
    {
        return $this->deviceManager->getAllDevices();
    }

    /**
     * 获取指定设备信息
     */
    public function getDevice(string $deviceId): ?array
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
     * @return string 规范化后的UTF-8 XML
     */
    private function normalizeXmlEncoding(string $xml): string
    {
        // 检测是否包含乱码（UTF-8环境下显示为 � 或 \xXX）
        // 或者直接检测编码
        $detectedEncoding = mb_detect_encoding($xml, ['UTF-8', 'GB2312', 'GBK', 'GB18030'], true);

        if ($detectedEncoding && $detectedEncoding !== 'UTF-8') {
            if ($this->config['debug']) {
                $this->log("检测到非UTF-8编码: {$detectedEncoding}，进行转换", 'DEBUG');
            }

            // 转换编码
            $xml = mb_convert_encoding($xml, 'UTF-8', $detectedEncoding);

            // 修正XML声明（如果存在）
            $xml = preg_replace(
                '/<\?xml\s+version="[^"]*"\s+encoding="[^"]*"\s*\?>/i',
                '<?xml version="1.0" encoding="UTF-8"?>',
                $xml
            );
        }

        return $xml;
    }


    // ========== Task 异步处理 ==========

    /**
     * 投递异步任务到 Task 进程
     */
    private function postTask(string $type, array $payload): void
    {
        // 检查是否支持 addTask 方法（多进程模式）
        if (!method_exists($this->sipServer, 'addTask')) {
            // 单进程模式，直接同步处理
            $this->log("单进程模式，同步处理任务: $type", 'DEBUG');
            return;
        }

        !isset($payload['timestamp']) && $payload['timestamp'] = time();

        try {
            $taskId = $this->sipServer->addTask([
                'type' => $type,
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
     * 判断是否为注销请求
     */
    private function isUnregisterRequest(\SipEvent $event): bool
    {
        return $event->getExpires() === 0;
    }

    /**
     * 检查是否包含 Authorization 头（包括 Capability 和 Digest）
     */
    private function hasAuthorizationHeader(\SipEvent $event): bool
    {
        // 尝试不同的大小写变体
        $authHeader = $event->getHeader('Authorization')
            ?: $event->getHeader('authorization')
                ?: $event->getHeader('AUTHORIZATION');

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
    private function validateAuthorization(\SipEvent $event, string $deviceId): bool
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
    private function validateDigestAuth(string $authHeader, \SipEvent $event, string $deviceId): bool
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
    private function getDeviceCapability(\SipEvent $event): ?string
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
    private function parseDigestAuth(string $authHeader): array
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
    private function generateNonce(): string
    {
        return md5(uniqid() . time() . rand());
    }


    /**
     * 获取设备密码
     * GB28181标准：服务器端使用统一的接入密码
     * 所有设备在NVR/IPC的"国标配置"中填写相同的密码
     */
    private function getDevicePassword(string $deviceId): string
    {
        // 返回统一的接入密码
        return $this->config['device_password'] ?? '12345678';
    }

    /**
     * 日志输出
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        $this->logger->log($message, $level, 'GB28181');
    }
}
