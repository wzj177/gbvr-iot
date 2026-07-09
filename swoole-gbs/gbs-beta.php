<?php
declare(strict_types = 1);

/**
 * GBServer.php
 *
 * 说明：
 *    在本版本中统一替换为 Swoole 协程（Coroutine）+ Coroutine\Channel 、Swoole\Coroutine\Lock 、 Swoole\Coroutine\Socket（协程化IO）、Swoole\Coroutine\Http\Client。
 *  - ZLMediaKitApi（$zlm）与 Logger（$logger）以依赖注入方式传入：
 *      $zlm->openRtpServer(int $port, int $tcpMode, string $streamId): array{0:bool,1:string,2:int}
 *      $zlm->closeRtpServer(string $streamId): array{0:int,1:string}
 *      $logger->debug(string $msg); $logger->info(string $msg); $logger->error(string $msg);
 *
 * 需要 ext-swoole >= 6.6，且在协程环境（Swoole\Coroutine\run 或 Swoole\Server worker）中运行。
 */

use Swoole\Coroutine;
use Swoole\Coroutine\Socket as CoSocket;
use Swoole\Coroutine\Lock as CoLock;
use Swoole\Coroutine\Channel as CoChannel;
use Swoole\Coroutine\Http\Client as CoHttpClient;
use Swoole\Timer;

// =====================================================================
// 工具函数：安全解析 XML（兼容带 encoding 声明的字符串，兼容 GB2312 编码设备）
// =====================================================================
function gb_safe_xml_parse(string $body) : ?\SimpleXMLElement
{
    // 移除XML声明中的encoding属性（如 encoding="GB2312"、encoding="UTF-8" 等）
    $cleaned = preg_replace('/encoding\s*=\s*["\'][^"\']*["\']/i', '', $body, 1);
    libxml_use_internal_errors(true);
    $xml = @simplexml_load_string((string)$cleaned);
    libxml_clear_errors();
    return $xml === false ? null : $xml;
}

/** 取 SimpleXMLElement 节点的文本，节点不存在返回空字符串 */
function gb_xml_text($node) : string
{
    if ($node === null) return '';
    if ($node instanceof \SimpleXMLElement) {
        return trim((string)$node);
    }
    return '';
}

/** root->find('Tag') 的等价实现：只取第一层直接子节点 */
function gb_xml_child(?\SimpleXMLElement $root, string $tag) : ?\SimpleXMLElement
{
    if ($root === null) return null;
    if (isset($root->{$tag})) {
        $n = $root->{$tag};
        return ($n instanceof \SimpleXMLElement) ? $n : null;
    }
    return null;
}

/** root->findall('.//Item') 的等价实现：递归查找所有层级的 <Item> 节点 */
function gb_xml_find_all(?\SimpleXMLElement $root, string $tag) : array
{
    if ($root === null) return [];
    $result = $root->xpath('//' . $tag);
    return $result === false ? [] : $result;
}

// =====================================================================
// Device：GB28181设备类
// =====================================================================
final class Device
{
    public string $deviceId;
    public ?string $ip;
    public ?int $port;
    public bool $registered = false;
    public ?string $registerTime = null;
    /** @var Channel[] */
    public array $channels = [];
    public ?string $callId = null;
    public ?string $fromTag = null;
    public ?string $toTag = null;
    public int $lastRegisterTime = 0;   // 毫秒时间戳
    public int $lastKeepaliveTime = 0;  // 毫秒时间戳
    public string $name = '';
    public string $manufacturer = '';
    public string $model = '';
    public string $firmwareVersion = '';

    public function __construct(string $deviceId, ?string $ip = null, ?int $port = null)
    {
        $this->deviceId = $deviceId;
        $this->ip = $ip;
        $this->port = $port;
    }
}

// =====================================================================
// Channel：GB28181通道类（支持多级目录结构）
// =====================================================================
final class Channel
{
    public string $channelId;
    public string $name;
    public ?Device $device;
    public ?string $deviceId;
    public $logger;
    public string $status = 'OFF';
    public string $streamUrl = '';

    // 多级目录支持
    public int $parental = 0;      // 0:叶子通道, 1:目录节点
    public string $parentId = '';
    public string $deviceType = '';
    public int $safetyWay = 0;
    public int $registerWay = 0;
    public int $secrecy = 0;
    /** @var Channel[] */
    public array $children = [];

    // Catalog信息
    public int $sumNum = 0;
    public string $manufacturer = '';
    public string $model = '';
    public string $owner = '';
    public string $civilCode = '';

    // INVITE会话状态
    public int $rtpPort = 0;
    public int $allocatedRtpPort = 0;
    public string $callId = '';
    public string $fromTag = '';
    public string $toTag = '';
    public string $dialogId = '';
    public bool $streaming = false;
    public bool $inviting = false;
    public int $sn = 0;

    // 同步状态
    public int $forwardState = 0; // 0:未转发 1:转发中
    public int $lastKeepaliveTime = 0;
    public int $lastRegisterTime = 0;

    public function __construct(string $channelId, string $name = '', ?Device $device = null, $logger = null)
    {
        $this->channelId = $channelId;
        $this->name = $name;
        $this->device = $device;
        $this->deviceId = $device ? $device->deviceId : null;
        $this->logger = $logger;
    }

    /**
     * 同步通道信息到 rebekah_admin 数据库（与 C++ /  版本逻辑一致）
     * @return array{0: bool, 1: string}
     */
    public function updateAdmin(GB28181SipServer $server) : array
    {
        $adminHost = $server->adminHost;
        if (!$adminHost) {
            return [false, 'admin_host not configured'];
        }

        $url = rtrim($adminHost, '/') . '/inner/on_media_update_stream';

        $server->lock->lock();
        try {
            $device = $server->devices[$this->deviceId] ?? null;
            if ($device) {
                $deviceIp = $device->ip;
                $devicePort = $device->port;
                $clientId = $device->deviceId;
            } else {
                $deviceIp = '';
                $devicePort = 0;
                $clientId = '';
            }
        } finally {
            $server->lock->unlock();
        }

        $params = [
            'forwardState'         => $this->forwardState,
            'app'                  => 'rtp',
            'name'                 => $this->channelId,
            'ip'                   => $deviceIp,
            'port'                 => $devicePort,
            'clientId'             => $clientId ? : '',
            'parentID'             => $this->parentId ? : '',
            'rtpServerPort'        => $this->rtpPort,
            'rtpPort'              => 0,
            'pullStreamType'       => 21, // 21=GB28181
            'pullStreamUrl'        => 'rtp://__',
            'cameraSumNum'         => $this->sumNum,
            'cameraName'           => $this->name ? : '',
            'cameraManufacturer'   => $this->manufacturer ? : 'unknown',
            'cameraModel'          => $this->model ? : '',
            'cameraOwner'          => $this->owner ? : '',
            'cameraCivilCode'      => $this->civilCode ? : '',
            'lastKeepaliveTime'    => $this->lastKeepaliveTime,
            'lastRegisterTime'     => $this->lastRegisterTime,
            'rtpTransferMode'      => $server->rtpTransferMode,
            'rtpTransferAudioType' => $server->rtpTransferAudioType,
        ];

        $maxRetries = 3;
        $retryInterval = 1.0;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                [$ok, $status, $body, $err] = gb_http_post_json($url, $params, 5.0);

                if ($err !== null) {
                    // 连接错误/超时：admin服务可能未就绪，重试
                    if ($attempt < $maxRetries) {
                        $this->logger?->debug("[GSS] [通道同步重试 {$attempt}/{$maxRetries}] {$this->channelId}: " . substr($err, 0, 100));
                        Coroutine::sleep($retryInterval);
                        continue;
                    }
                    $this->logger?->debug("[GSS] [通道同步异常] {$this->channelId}: 重试{$maxRetries}次后仍失败: " . substr($err, 0, 100));
                    return [false, $err];
                }

                if ($status === 200) {
                    $result = json_decode((string)$body, true);
                    if (is_array($result) && ($result['code'] ?? null) == 1000) {
                        return [true, 'success'];
                    }
                    $msg = is_array($result) ? ($result['msg'] ?? 'unknown error') : 'unknown error';
                    $this->logger?->debug("[GSS] [通道同步失败] {$this->channelId}: {$msg}");
                    return [false, $msg];
                }

                $this->logger?->debug("[GSS] [通道同步HTTP错误] {$this->channelId}: HTTP {$status}");
                return [false, "HTTP {$status}"];
            } catch (\Throwable $e) {
                $this->logger?->debug("[GSS] [通道同步异常] {$this->channelId}: " . $e->getMessage());
                return [false, $e->getMessage()];
            }
        }

        return [false, 'max retries exceeded'];
    }
}

/**
 * 简单的 JSON POST 请求封装（基于 Swoole\Coroutine\Http\Client）
 * @return array{0: bool, 1: int, 2: string, 3: ?string} [ok, statusCode, body, errorMessageOrNull]
 */
function gb_http_post_json(string $url, array $params, float $timeout = 5.0) : array
{
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return [false, 0, '', 'invalid url: ' . $url];
    }
    $ssl = ($parts['scheme'] ?? 'http') === 'https';
    $host = $parts['host'];
    $port = $parts['port'] ?? ($ssl ? 443 : 80);
    $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? ('?' . $parts['query']) : '');

    $cli = new CoHttpClient($host, $port, $ssl);
    $cli->set(['timeout' => $timeout]);
    $cli->setHeaders(['Content-Type' => 'application/json;']);
    try {
        $ok = $cli->post($path, (string)json_encode($params));
        if (!$ok) {
            $errMsg = $cli->errMsg ? : 'connection error';
            $cli->close();
            return [false, 0, '', $errMsg];
        }
        $status = $cli->statusCode;
        $body = (string)$cli->body;
        $cli->close();
        return [true, $status, $body, null];
    } catch (\Throwable $e) {
        $cli->close();
        return [false, 0, '', $e->getMessage()];
    }
}

// =====================================================================
// RTPPortManager：RTP端口管理器（与 C++/ 版本逻辑一致）
// =====================================================================
final class RTPPortManager
{
    private int $minPort;
    private int $maxPort;
    private int $currentPort;
    private CoLock $lock;
    /** @var array<int,string> */
    private array $allocatedPorts = [];

    public function __construct(int $minPort = 20002, int $maxPort = 30000)
    {
        $this->minPort = $minPort;
        $this->maxPort = $maxPort;
        $this->currentPort = $minPort;
        $this->lock = new CoLock();
    }

    public function allocate(string $channelId) : int
    {
        $this->lock->lock();
        try {
            $iterations = intdiv($this->maxPort - $this->minPort, 2);
            for ($i = 0; $i < $iterations; $i++) {
                if ($this->currentPort >= $this->maxPort) {
                    $this->currentPort = $this->minPort;
                }
                $port = $this->currentPort;
                $this->currentPort += 2; // RTP使用偶数端口

                if (!isset($this->allocatedPorts[$port])) {
                    if ($this->isPortAvailable($port)) {
                        $this->allocatedPorts[$port] = $channelId;
                        return $port;
                    }
                }
            }
            return 0; // 无可用端口
        } finally {
            $this->lock->unlock();
        }
    }

    public function release(int $port) : void
    {
        $this->lock->lock();
        try {
            unset($this->allocatedPorts[$port]);
        } finally {
            $this->lock->unlock();
        }
    }


    private function isPortAvailable(int $port) : bool
    {
        $sock = new CoSocket(AF_INET, SOCK_DGRAM, 0);
        $ok = @$sock->bind('0.0.0.0', $port);
        $sock->close();
        return $ok !== false;
    }

    //    private function isPortAvailable(int $port) : bool
    //    {
    //        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    //        if ($sock === false) return false;
    //        $ok = @socket_bind($sock, '0.0.0.0', $port);
    //        socket_close($sock);
    //        return $ok !== false;
    //    }
}

// =====================================================================
// GBServer：GB28181 SIP服务器
// =====================================================================
final class GBServer
{
    public string $serverIp;
    public int $serverPort;
    public string $serverId;
    public string $realm;
    public string $password;

    public int $sipServerTimeout;
    public int $sipServerExpiry;
    public int $sipTransferMode;
    public int $rtpTransferMode;
    public int $rtpTransferAudioType;

    private string $listenIp = '0.0.0.0';

    public bool $autoQueryCatalog = true;
    public bool $autoInviteAfterRecCateLog;

    public ?string $adminHost;
    public $zlm;
    public $logger;

    private ?CoSocket $sock = null;
    private ?CoSocket $listenSock = null;
    public bool $running = false;

    /** @var array<string,Device> */
    public array $devices = [];
    public CoLock $lock;

    /** @var array<string,CoSocket> */
    private array $tcpConnections = [];
    private CoLock $tcpLock;

    private CoChannel $messageQueue;

    /** @var array<string,float> */
    private array $lastCatalogQueryTime = [];
    private int $catalogQueryDebounceSeconds = 10;

    /** @var array<string,array> */
    private array $pendingInvites = [];
    private CoLock $pendingInvitesLock;

    /** @var array<string,CoLock> */
    private array $catalogLocks = [];
    private CoLock $catalogLocksGuard;

    private CoChannel $catalogQuerySemaphore;
    private CoChannel $workerSemaphore;

    public RTPPortManager $rtpPortMgr;

    /** @var array<string,mixed> */
    public array $stats
        = [
            'total_registers' => 0,
            'total_invites'   => 0,
            'total_byes'      => 0,
            'total_messages'  => 0,
            'message_types'   => [
                'Keepalive'  => 0,
                'Catalog'    => 0,
                'DeviceInfo' => 0,
                'Other'      => 0,
            ],
        ];

    private array $timers = [];

    public function __construct(
        string $serverIp,
        int $serverPort,
        string $serverId,
        string $realm,
        string $password,
        int $sipServerTimeout = 120,
        int $sipServerExpiry = 60,
        int $sipTransferMode = 0,
        int $rtpTransferMode = 0,
        int $rtpTransferAudioType = 0,
        bool $autoInviteAfterRecCateLog = true,
        ?string $adminHost = null,
        $zlm = null,
        $logger = null
    )
    {
        $this->serverIp = $serverIp;
        $this->serverPort = $serverPort;
        $this->serverId = $serverId;
        $this->realm = $realm;
        $this->password = $password;
        $this->sipServerTimeout = $sipServerTimeout;
        $this->sipServerExpiry = $sipServerExpiry;
        $this->sipTransferMode = $sipTransferMode;
        $this->rtpTransferMode = $rtpTransferMode;
        $this->rtpTransferAudioType = $rtpTransferAudioType;

        $this->autoInviteAfterRecCateLog = $autoInviteAfterRecCateLog;
        $this->adminHost = $adminHost;
        $this->zlm = $zlm;
        $this->logger = $logger;

        $this->logger?->debug('GB28181SipServer.__init__()');

        $this->lock = new CoLock();
        $this->tcpLock = new CoLock();
        $this->messageQueue = new CoChannel(10000);
        $this->pendingInvitesLock = new CoLock();
        $this->catalogLocksGuard = new CoLock();
        $this->catalogQuerySemaphore = new CoChannel(10);
        $this->workerSemaphore = new CoChannel(50);
        for ($i = 0; $i < 50; $i++) {
            $this->workerSemaphore->push(1);
        }

        $this->rtpPortMgr = new RTPPortManager();
    }

    public function sendSipResponse(string $responseMsg, array $addr) : bool
    {
        $success = $this->sendData($responseMsg, $addr);
        if ($success) {
            $this->logSipPacket('TX', $responseMsg, $addr);
        }
        return $success;
    }

    private function sendData(string $data, array $addr) : bool
    {
        if ($this->sipTransferMode === 1) {
            $key = $addr[0] . ':' . $addr[1];
            $this->tcpLock->lock();
            $clientSock = $this->tcpConnections[$key] ?? null;
            $this->tcpLock->unlock();

            if ($clientSock) {
                try {
                    $clientSock->sendAll($data);
                    return true;
                } catch (\Throwable $e) {
                    $this->logger?->error("[GSS] TCP发送失败 {$key}: " . $e->getMessage());
                    $this->tcpLock->lock();
                    unset($this->tcpConnections[$key]);
                    $this->tcpLock->unlock();
                    return false;
                }
            }
            $this->logger?->error("[GSS] TCP连接不存在 {$key}，无法发送数据");
            return false;
        }

        try {
            $this->sock?->sendto($addr[0], $addr[1], $data);
            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] UDP发送失败 ' . $addr[0] . ':' . $addr[1] . ': ' . $e->getMessage());
            return false;
        }
    }

    private function getSipTransport() : string
    {
        return $this->sipTransferMode === 1 ? 'TCP' : 'UDP';
    }

    public function logSipPacket(string $direction, string $data, ?array $addr = null) : void
    {
        $addrStr = $addr ? ($addr[0] . ':' . $addr[1]) : '?';
        $lines = explode("\r\n", $data);
        $firstLine = $lines[0] ?? '';
        $this->logger?->debug("[GSS] [{$direction}] {$addrStr} | {$firstLine}");
        $this->logger?->debug("[GSS] [{$direction}] 完整消息:\n{$data}");
    }

    public function start() : void
    {
        $this->logger?->debug('[GSS] start...');

        if ($this->sipTransferMode === 1) {
            $this->listenSock = new CoSocket(AF_INET, SOCK_STREAM, 0);
            $this->listenSock->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
            $this->listenSock->bind($this->listenIp, $this->serverPort);
            $this->listenSock->listen(5);
            $this->logger?->debug("[GSS] SIP服务器已启动: TCP {$this->listenIp}:{$this->serverPort} -> {$this->serverIp}:{$this->serverPort}");
        } else {
            $this->sock = new CoSocket(AF_INET, SOCK_DGRAM, 0);
            $this->sock->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
            $this->sock->bind($this->listenIp, $this->serverPort);
            $this->logger?->debug("[GSS] SIP服务器已启动: UDP {$this->listenIp}:{$this->serverPort} -> {$this->serverIp}:{$this->serverPort}");
        }

        $this->running = true;

        if ($this->sipTransferMode === 1) {
            Coroutine::create(function () {
                $this->tcpReceiveLoop();
            });
        } else {
            Coroutine::create(function () {
                $this->receiveLoop();
            });
        }

        for ($i = 0; $i < 3; $i++) {
            Coroutine::create(function () {
                $this->processMessageQueue();
            });
        }

        Coroutine::create(function () {
            $this->checkDevicesLoop();
        });
    }

    public function stop() : void
    {
        $this->logger?->debug('[GSS] stop...');
        $this->running = false;

        $activeStreamCount = 0;
        $this->lock->lock();
        try {
            foreach ($this->devices as $device) {
                foreach ($device->channels as $channel) {
                    if ($channel->streaming || $channel->forwardState === 1) {
                        $channelId = $channel->channelId;
                        $rtpPort = $channel->allocatedRtpPort;
                        try {
                            $this->closeRtpServer($channelId);
                        } catch (\Throwable $e) {
                            $this->logger?->error("[GSS] ✗ Failed to close RTP server for {$channelId}: " . $e->getMessage());
                        }
                        if ($rtpPort > 0) {
                            try {
                                $this->rtpPortMgr->release($rtpPort);
                            } catch (\Throwable $e) {
                                $this->logger?->error("[GSS] ✗ Failed to release port {$rtpPort}: " . $e->getMessage());
                            }
                        }
                        $activeStreamCount++;
                    }
                }
            }
        } finally {
            $this->lock->unlock();
        }

        if ($activeStreamCount > 0) {
            $this->logger?->debug("[GSS] 🛑 Stopped {$activeStreamCount} active streams");
        }

        $this->pendingInvitesLock->lock();
        $pendingCount = count($this->pendingInvites);
        foreach ($this->pendingInvites as $channelId => $pending) {
            try {
                $this->rtpPortMgr->release((int)$pending['rtp_port']);
                $this->closeRtpServer($channelId);
            } catch (\Throwable $e) {
                $this->logger?->error("[GSS] ✗ Failed to clean pending invite {$channelId}: " . $e->getMessage());
            }
        }
        $this->pendingInvites = [];
        $this->pendingInvitesLock->unlock();

        if ($pendingCount > 0) {
            $this->logger?->debug("[GSS] 🧹 Cleaned {$pendingCount} pending invites");
        }

        while (!$this->messageQueue->isEmpty()) {
            $this->messageQueue->pop(0.01);
        }
        $this->logger?->debug('[GSS] ✓ 消息队列已清空');

        foreach ($this->timers as $tid) {
            Timer::clear($tid);
        }

        if ($this->sock) {
            $this->sock->close();
        }
        if ($this->listenSock) {
            $this->listenSock->close();
        }
        $this->logger?->debug('[GSS] stop finish');
    }

    // -------------------------------------------------------------
    // 接收循环
    // -------------------------------------------------------------

    private function receiveLoop() : void
    {
        while ($this->running) {
            try {
                $peer = [];
                $data = $this->sock->recvfrom($peer, 65535);
                if ($data === false || $data === '') {
                    if ($this->sock->errCode === SOCKET_ETIMEDOUT) continue;
                    if (!$this->running) break;
                    continue;
                }
                $message = $this->decodeMessage($data);
                $addr = [$peer['address'], $peer['port']];
                $this->logSipPacket('RX', $message, $addr);
                if (!$this->messageQueue->push([$message, $addr], 0.001)) {
                    $this->logger?->debug('[GSS] 消息队列已满，丢弃消息');
                }
            } catch (\Throwable $e) {
                if ($this->running) {
                    $this->logger?->error('[GSS] 接收消息异常: ' . $e->getMessage());
                }
            }
        }
    }

    /** 优先UTF-8解码，失败则使用GBK（兼容GB2312编码的小众GB28181设备） */
    private function decodeMessage(string $data) : string
    {
        if (mb_check_encoding($data, 'UTF-8')) {
            return $data;
        }
        $converted = @iconv('GBK', 'UTF-8//IGNORE', $data);
        return $converted === false ? $data : $converted;
    }

    private function processMessageQueue() : void
    {
        while ($this->running) {
            $item = $this->messageQueue->pop(1.0);
            if ($item === false) {
                continue;
            }
            [$message, $addr] = $item;
            // 限流：对应  ThreadPoolExecutor(max_workers=50)
            $token = $this->workerSemaphore->pop(5.0);
            Coroutine::create(function () use ($message, $addr, $token) {
                try {
                    $this->handleMessage($message, $addr);
                } finally {
                    if ($token !== false) {
                        $this->workerSemaphore->push(1);
                    }
                }
            });
        }
    }

    private function tcpReceiveLoop() : void
    {
        while ($this->running) {
            try {
                $clientSock = $this->listenSock->accept(1.0);
                if ($clientSock === false) {
                    continue;
                }
                $peer = $clientSock->getpeername();
                $addr = [$peer['address'], $peer['port']];
                $this->logger?->debug("[GSS] TCP新连接: {$addr[0]}:{$addr[1]}");
                Coroutine::create(function () use ($clientSock, $addr) {
                    $this->handleTcpClient($clientSock, $addr);
                });
            } catch (\Throwable $e) {
                if ($this->running) {
                    $this->logger?->error('[GSS] TCP接受连接异常: ' . $e->getMessage());
                }
            }
        }
    }

    private function handleTcpClient(CoSocket $clientSock, array $addr) : void
    {
        $buffer = '';
        $key = $addr[0] . ':' . $addr[1];

        $this->tcpLock->lock();
        $this->tcpConnections[$key] = $clientSock;
        $this->tcpLock->unlock();

        try {
            while ($this->running) {
                $data = $clientSock->recv(65535);
                if ($data === '' || $data === false) {
                    break; // 连接关闭
                }
                $buffer .= $data;

                while (($pos = strpos($buffer, "\r\n\r\n")) !== false) {
                    $headerBytes = substr($buffer, 0, $pos);
                    $contentLength = 0;
                    foreach (explode("\r\n", $headerBytes) as $line) {
                        if (stripos($line, 'content-length:') === 0) {
                            $contentLength = (int)trim(substr($line, strpos($line, ':') + 1));
                        }
                    }

                    $completeMsgLen = strlen($headerBytes) + 4 + $contentLength;
                    if (strlen($buffer) >= $completeMsgLen) {
                        $rawMsg = substr($buffer, 0, $completeMsgLen);
                        $message = $this->decodeMessage($rawMsg);
                        $buffer = substr($buffer, $completeMsgLen);

                        $this->logSipPacket('RX', $message, $addr);
                        $this->handleMessage($message, $addr);
                    } else {
                        break; // 等待更多数据
                    }
                }
            }
        } catch (\Throwable $e) {
            if ($this->running) {
                $this->logger?->error('[GSS] TCP客户端处理异常: ' . $e->getMessage());
            }
        } finally {
            $this->tcpLock->lock();
            unset($this->tcpConnections[$key]);
            $this->tcpLock->unlock();
            $this->logger?->debug("[GSS] TCP连接断开: {$addr[0]}:{$addr[1]}");
            $clientSock->close();
        }
    }

    private function checkDevicesLoop() : void
    {
        $catalogCheckCounter = 0;
        $catalogCheckIntervalCycles = 5; // 每次循环60秒，5*60=300秒
        while ($this->running) {
            Coroutine::sleep(60);
            if (!$this->running) break;
            $this->checkExpiredDevices();

            $catalogCheckCounter++;
            if ($catalogCheckCounter >= $catalogCheckIntervalCycles) {
                $catalogCheckCounter = 0;
                try {
                    $this->lock->lock();
                    $deviceIds = array_keys($this->devices);
                    $this->lock->unlock();
                    foreach ($deviceIds as $deviceId) {
                        $this->queryCatalog($deviceId);
                    }
                } catch (\Throwable $e) {
                    $this->logger?->error('[GSS] 定时Catalog查询失败: ' . $e->getMessage());
                }
            }
        }
    }

    // -------------------------------------------------------------
    // 消息分发
    // -------------------------------------------------------------

    private function handleMessage(string $message, array $addr) : void
    {
        try {
            $lines = explode("\r\n", $message);
            if (!$lines) return;

            $firstLine = $lines[0];
            $this->logger?->debug("[GSS] [RX] {$addr[0]}:{$addr[1]} | {$firstLine}");

            $this->lock->lock();
            if (str_starts_with($firstLine, 'REGISTER')) {
                $this->stats['total_registers']++;
            } else if (str_starts_with($firstLine, 'INVITE')) {
                $this->stats['total_invites']++;
            } else if (str_starts_with($firstLine, 'MESSAGE')) {
                $this->stats['total_messages']++;
            }
            $this->lock->unlock();

            if (str_starts_with($firstLine, 'REGISTER')) {
                $this->handleRegister($message, $addr);
            } else if (str_starts_with($firstLine, 'INVITE')) {
                $this->handleInvite($message, $addr);
            } else if (str_starts_with($firstLine, 'MESSAGE')) {
                $this->handleMessageRequest($message, $addr);
            } else if (str_starts_with($firstLine, 'NOTIFY')) {
                $this->handleNotify($message, $addr);
            } else if (str_starts_with($firstLine, 'BYE')) {
                $this->handleBye($message, $addr);
            } else if (str_starts_with($firstLine, 'ACK')) {
                $this->handleAck($message, $addr);
            } else {
                $this->handleResponse($message, $addr);
            }
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] _handle_message 异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }

    private function parseSipHeaders(string $message) : array
    {
        $headers = [];
        $lines = explode("\r\n", $message);
        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];
            if ($line === '') break;
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    private function parseRequestLine(string $firstLine) : array
    {
        $parts = explode(' ', $firstLine);
        if (count($parts) >= 3) {
            return ['method' => $parts[0], 'uri' => $parts[1], 'version' => $parts[2]];
        }
        return [];
    }

    private function generateNonce() : string
    {
        return md5(microtime(true) . ':' . mt_rand() / mt_getrandmax());
    }

    private function calculateResponse(
        string $username, string $realm, string $password, string $nonce,
        string $method, string $uri, string $qop = '', string $cnonce = '', string $nc = ''
    ) : string
    {
        $ha1 = md5("{$username}:{$realm}:{$password}");
        $ha2 = md5("{$method}:{$uri}");

        if ($qop !== '') {
            return md5("{$ha1}:{$nonce}:{$nc}:{$cnonce}:{$qop}:{$ha2}");
        }
        return md5("{$ha1}:{$nonce}:{$ha2}");
    }

    private function sendDebugRawMessage(string $rawMessage, array $addr) : bool
    {
        return $this->sendSipResponse($rawMessage, $addr);
    }

    // -------------------------------------------------------------
    // REGISTER
    // -------------------------------------------------------------

    private function handleRegister(string $message, array $addr) : void
    {
        try {
            $headers = $this->parseSipHeaders($message);
            $requestInfo = $this->parseRequestLine(explode("\r\n", $message)[0]);

            $fromHeader = $headers['From'] ?? '';
            $deviceId = '';
            if (str_contains($fromHeader, '<sip:')) {
                $start = strpos($fromHeader, '<sip:') + 5;
                $end = strpos($fromHeader, '>', $start);
                if ($end !== false && $end > $start) {
                    $uriPart = substr($fromHeader, $start, $end - $start);
                    $deviceId = explode('@', $uriPart)[0];
                }
            }

            if ($deviceId === '') {
                $this->logger?->error('[GSS] [REGISTER] 无法提取设备ID');
                return;
            }

            $authHeader = $headers['Authorization'] ?? '';

            if ($authHeader === '') {
                $this->send401Unauthorized($message, $addr);
                return;
            }

            if ($this->verifyAuth($authHeader, $requestInfo['uri'] ?? '', 'REGISTER', $deviceId)) {
                $receivedIp = $this->extractReceivedIp($message);
                $rport = $this->extractRport($message);
                if ($receivedIp !== '' && $rport > 0) {
                    $addr = [$receivedIp, $rport];
                }

                $channelsToBye = [];

                $this->lock->lock();
                if (!isset($this->devices[$deviceId])) {
                    $this->devices[$deviceId] = new Device($deviceId, $addr[0], $addr[1]);
                } else {
                    $device = $this->devices[$deviceId];
                    $device->ip = $addr[0];
                    $device->port = $addr[1];
                }
                $device = $this->devices[$deviceId];
                $device->registered = true;
                $device->registerTime = date('Y-m-d H:i:s');
                $device->lastRegisterTime = (int)(microtime(true) * 1000);
                $fromTagVal = null;
                if (str_contains($fromHeader, 'tag=')) {
                    $tagParts = explode('tag=', $fromHeader);
                    $fromTagVal = end($tagParts);
                }
                $device->fromTag = $fromTagVal;
                $device->callId = $headers['Call-ID'] ?? '';
                $devicesCount = count($this->devices);
                $this->lock->unlock();

                foreach ($channelsToBye as $byeInfo) {
                    $this->sendByeForChannel($addr[0], $addr[1], $byeInfo['channel_id'], $byeInfo['call_id'], $byeInfo['from_tag'], $byeInfo['to_tag']);
                }

                $this->send200Ok($message, $addr, $device);
                $this->logger?->info("[GSS] [REGISTER] 设备 {$deviceId} 注册成功 (共{$devicesCount}台)");

                if ($this->autoQueryCatalog) {
                    $now = microtime(true);
                    $lastQuery = $this->lastCatalogQueryTime[$deviceId] ?? 0;
                    if ($now - $lastQuery >= $this->catalogQueryDebounceSeconds) {
                        $this->lastCatalogQueryTime[$deviceId] = $now;
                        $this->logger?->debug("[GSS] [CATALOG] 准备查询设备 {$deviceId} 的通道列表");
                        Coroutine::create(function () use ($deviceId) {
                            $this->queryCatalog($deviceId);
                        });
                        Coroutine::create(function () use ($deviceId) {
                            $this->queryDeviceInfo($deviceId);
                        });
                    } else {
                        $delta = (int)($now - $lastQuery);
                        $this->logger?->debug("[GSS] [REGISTER] 跳过Catalog查询（距上次查询{$delta}秒，防抖{$this->catalogQueryDebounceSeconds}秒）");
                    }
                }
            } else {
                $this->logger?->info("[GSS] [REGISTER] 设备 {$deviceId} 认证失败");
                $this->send401Unauthorized($message, $addr);
            }
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] _handle_register 异常: ' . $e->getMessage());
            try {
                $this->send401Unauthorized($message, $addr);
            } catch (\Throwable $e2) {
            }
        }
    }

    // -------------------------------------------------------------
    // INVITE（被动接收模式：回复100 Trying + 200 OK）
    // -------------------------------------------------------------

    private function handleInvite(string $message, array $addr) : void
    {
        try {
            $headers = $this->parseSipHeaders($message);

            $receivedIp = $this->extractReceivedIp($message);
            $rport = $this->extractRport($message);
            if ($receivedIp !== '' && $rport > 0) {
                $addr = [$receivedIp, $rport];
            }

            $this->send100Trying($message, $addr);
            $this->send200OkInvite($message, $addr, $headers);
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] _handle_invite 异常: ' . $e->getMessage());
            try {
                $this->sendResponse($message, $addr, 500, 'Internal Server Error');
            } catch (\Throwable $e2) {
            }
        }
    }

    // -------------------------------------------------------------
    // MESSAGE（心跳 / 目录查询 / 设备信息）
    // -------------------------------------------------------------

    private function handleMessageRequest(string $message, array $addr) : void
    {
        try {
            $body = '';
            if (str_contains($message, "\r\n\r\n")) {
                $body = explode("\r\n\r\n", $message, 2)[1];
            }

            $deviceId = '';
            if (preg_match('/<DeviceID>(.*?)<\/DeviceID>/', $body, $m)) {
                $deviceId = $m[1];
            }

            $this->lock->lock();
            $device = $this->devices[$deviceId] ?? null;
            $isRegistered = $device && $device->registered;
            $this->lock->unlock();

            $receivedIp = $this->extractReceivedIp($message);
            $rport = $this->extractRport($message);
            if ($receivedIp !== '' && $rport > 0) {
                $addr = [$receivedIp, $rport];
            }

            if (str_contains($body, 'Keepalive')) {
                $this->lock->lock();
                $this->stats['message_types']['Keepalive']++;
                $this->lock->unlock();

                if (!$isRegistered) {
                    $this->logger?->debug("[GSS] [MESSAGE] 未注册设备 {$deviceId} 发送心跳，忽略");
                    return;
                }

                if ($receivedIp !== '' && $rport > 0) {
                    $this->updateDeviceContact($deviceId, $receivedIp, $rport, $message);
                } else {
                    $this->updateDeviceContact($deviceId, $addr[0], $addr[1], $message);
                }

                $this->lock->lock();
                $device = $this->devices[$deviceId] ?? null;
                if ($device) {
                    $nowMs = (int)(microtime(true) * 1000);
                    $device->lastKeepaliveTime = $nowMs;
                    foreach ($device->channels as $channel) {
                        $channel->lastKeepaliveTime = $nowMs;
                    }
                }
                $this->lock->unlock();

                $this->sendResponse($message, $addr, 200, 'OK');
                return;
            }

            if (str_contains($body, 'Catalog')) {
                $this->lock->lock();
                $this->stats['message_types']['Catalog']++;
                $this->lock->unlock();

                $channelCount = 0;
                $channelIds = [];
                $channelTypes = [];
                try {
                    $root = gb_safe_xml_parse($body);
                    $itemList = gb_xml_find_all($root, 'Item');
                    if ($itemList) {
                        $channelCount = count($itemList);
                        foreach ($itemList as $item) {
                            $chIdElem = gb_xml_child($item, 'DeviceID');
                            $parentalElem = gb_xml_child($item, 'Parental');
                            if ($chIdElem !== null) {
                                $chId = gb_xml_text($chIdElem);
                                $parental = $parentalElem !== null && gb_xml_text($parentalElem) !== '' ? (int)gb_xml_text($parentalElem) : 0;
                                $channelIds[] = $chId;
                                $channelTypes[] = $parental === 1 ? '目录' : '叶子';
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger?->debug('[GSS] [MESSAGE] 解析Catalog XML失败: ' . $e->getMessage());
                }

                if ($channelCount > 0) {
                    $pairs = [];
                    for ($i = 0; $i < min(5, $channelCount); $i++) {
                        $pairs[] = "{$channelIds[$i]}({$channelTypes[$i]})";
                    }
                    $channelsInfo = implode(', ', $pairs);
                    if ($channelCount > 5) $channelsInfo .= " ... 等{$channelCount}个";
                    $this->logger?->debug("[GSS] [MESSAGE] 收到Catalog消息: 设备={$deviceId}, 通道数={$channelCount}, 通道=[{$channelsInfo}]");
                } else {
                    $this->logger?->debug("[GSS] [MESSAGE] 收到Catalog消息: 设备={$deviceId}, 长度=" . strlen($body));
                }

                if (!$isRegistered) {
                    $this->lock->lock();
                    $deviceExists = isset($this->devices[$deviceId]);
                    $this->lock->unlock();

                    if (!$deviceExists) {
                        $this->logger?->debug("[GSS] [MESSAGE] 未知设备 {$deviceId} 发送目录请求，拒绝");
                        $this->sendResponse($message, $addr, 403, 'Forbidden');
                        return;
                    }
                    $this->logger?->debug("[GSS] [MESSAGE] 设备 {$deviceId} 正在注册中，接受Catalog响应");
                }

                if ($receivedIp !== '' && $rport > 0) {
                    $this->updateDeviceContact($deviceId, $receivedIp, $rport, $message);
                } else {
                    $this->updateDeviceContact($deviceId, $addr[0], $addr[1], $message);
                }

                if (str_contains($body, '<Response>') || str_contains($body, '<Item>')) {
                    $respDeviceId = $deviceId;
                    if (preg_match('/<DeviceID>(.*?)<\/DeviceID>/', $body, $m2)) {
                        $respDeviceId = $m2[1];
                    }
                    $parentIdShown = $respDeviceId !== $deviceId ? $respDeviceId : '无';
                    $this->logger?->debug("[GSS] [MESSAGE] 收到目录响应: 设备={$deviceId}, 父ID={$parentIdShown}");
                    $this->parseCatalogResponse($body, $deviceId, $respDeviceId !== $deviceId ? $respDeviceId : '');
                } else {
                    $this->logger?->debug('[GSS] [MESSAGE] Catalog消息格式不正确，缺少<Response>或<Item>标签');
                    $this->logger?->debug('[GSS] [MESSAGE] Catalog消息内容: ' . substr($body, 0, 500));
                }
                $this->sendResponse($message, $addr, 200, 'OK');
                return;
            }

            if (str_contains($body, 'DeviceInfo')) {
                $this->lock->lock();
                $this->stats['message_types']['DeviceInfo']++;
                $this->lock->unlock();

                if (!$isRegistered) {
                    $this->logger?->debug("[GSS] [MESSAGE] 未注册设备 {$deviceId} 发送信息查询，拒绝");
                    $this->sendResponse($message, $addr, 403, 'Forbidden');
                    return;
                }

                try {
                    $root = gb_safe_xml_parse($body);
                    $deviceName = gb_xml_text(gb_xml_child($root, 'DeviceName'));
                    $manufacturer = gb_xml_text(gb_xml_child($root, 'Manufacturer'));
                    $model = gb_xml_text(gb_xml_child($root, 'Model'));
                    $firmware = gb_xml_text(gb_xml_child($root, 'FirmwareVersion'));

                    $this->logger?->debug("[GSS] [DEVICEINFO] 设备 {$deviceId} 信息: 名称={$deviceName}, 厂商={$manufacturer}, 型号={$model}, 固件={$firmware}");

                    $channelsToUpdate = [];
                    $this->lock->lock();
                    $device = $this->devices[$deviceId] ?? null;
                    if ($device) {
                        if ($deviceName !== '') $device->name = $deviceName;
                        if ($manufacturer !== '') $device->manufacturer = $manufacturer;
                        if ($model !== '') $device->model = $model;
                        if ($firmware !== '') $device->firmwareVersion = $firmware;

                        foreach ($device->channels as $ch) {
                            if ($deviceName !== '' && $ch->name === '') {
                                $oldName = $ch->name;
                                $ch->name = $deviceName;
                                if ($manufacturer !== '') $ch->manufacturer = $manufacturer;
                                if ($model !== '') $ch->model = $model;
                                $this->logger?->debug("[GSS] [DEVICEINFO] 通道 {$ch->channelId} 名称更新: '{$oldName}' → '{$ch->name}'");
                                $channelsToUpdate[] = $ch;
                            }
                        }
                    }
                    $this->lock->unlock();

                    if ($channelsToUpdate && $this->adminHost) {
                        foreach ($channelsToUpdate as $ch) {
                            Coroutine::create(function () use ($ch) {
                                $ch->updateAdmin($this);
                            });
                            $this->logger?->debug("[GSS] [DEVICEINFO] 通道 {$ch->channelId} 已重新同步到admin数据库（名称更新为'{$ch->name}'）");
                        }
                    }
                } catch (\Throwable $e) {
                    $this->logger?->error('[GSS] [DEVICEINFO] 处理DeviceInfo响应异常: ' . $e->getMessage());
                }

                $this->sendResponse($message, $addr, 200, 'OK');
                return;
            }

            $this->lock->lock();
            $this->stats['message_types']['Other']++;
            $this->lock->unlock();
            $this->sendResponse($message, $addr, 200, 'OK');
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] _handle_message_request 异常: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------
    // BYE / ACK / NOTIFY
    // -------------------------------------------------------------

    private function handleBye(string $message, array $addr) : void
    {
        try {
            $callId = $this->getHeader($message, 'Call-ID');
            if ($callId !== '') {
                $this->releaseChannelByCallid($callId);
            } else {
                $this->logger?->debug('[GSS] ⚠️ BYE请求中未找到Call-ID，无法释放通道资源');
            }
            $this->send200Ok($message, $addr, null);
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] _handle_bye 异常: ' . $e->getMessage());
            try {
                $this->sendResponse($message, $addr, 500, 'Internal Server Error');
            } catch (\Throwable $e2) {
            }
        }
    }

    private function handleAck(string $message, array $addr) : void
    {
        // ACK日志已精简，避免刷屏
    }

    private function handleNotify(string $message, array $addr) : void
    {
        try {
            $receivedIp = $this->extractReceivedIp($message);
            $rport = $this->extractRport($message);
            if ($receivedIp !== '' && $rport > 0) {
                $addr = [$receivedIp, $rport];
            }

            $body = '';
            if (str_contains($message, "\r\n\r\n")) {
                $body = explode("\r\n\r\n", $message, 2)[1];
            }

            $deviceId = '';
            if (preg_match('/<DeviceID>(.*?)<\/DeviceID>/', $body, $m)) {
                $deviceId = $m[1];
            }

            $this->sendResponse($message, $addr, 200, 'OK');

            if ($body === '') {
                $this->logger?->debug("[GSS] [NOTIFY] 设备 {$deviceId} 空消息体");
                return;
            }

            $this->logger?->debug("[GSS] [NOTIFY] 收到设备 {$deviceId} 的NOTIFY通知");

            if (str_contains($body, 'Catalog')) {
                if (str_contains($body, '<Response>') || str_contains($body, '<Item>')) {
                    $this->logger?->debug("[GSS] [NOTIFY] 设备 {$deviceId} 通过NOTIFY推送Catalog目录");
                    $this->parseCatalogResponse($body, $deviceId, '');
                } else {
                    $this->logger?->debug('[GSS] [NOTIFY] Catalog消息格式不正确，缺少<Response>或<Item>标签');
                }
            } else if (str_contains($body, 'Keepalive')) {
                $this->lock->lock();
                $device = $this->devices[$deviceId] ?? null;
                if ($device && $device->registered) {
                    $nowMs = (int)(microtime(true) * 1000);
                    $device->lastKeepaliveTime = $nowMs;
                    foreach ($device->channels as $channel) {
                        $channel->lastKeepaliveTime = $nowMs;
                    }
                }
                $this->lock->unlock();
            } else {
                $this->logger?->debug("[GSS] [NOTIFY] 设备 {$deviceId} 未识别的通知类型: " . substr($body, 0, 200));
            }
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] _handle_notify 异常: ' . $e->getMessage());
            try {
                $this->sendResponse($message, $addr, 500, 'Internal Server Error');
            } catch (\Throwable $e2) {
            }
        }
    }

    // -------------------------------------------------------------
    // 响应处理（设备对我们发起的INVITE/BYE/MESSAGE的回复）
    // -------------------------------------------------------------

    private function handleResponse(string $message, array $addr) : void
    {
        $lines = explode("\r\n", $message);
        if (!$lines) return;
        $firstLine = $lines[0];

        $parts = explode(' ', $firstLine, 3);
        if (count($parts) < 2) return;

        if (!is_numeric($parts[1])) return;
        $statusCode = (int)$parts[1];

        $headers = $this->parseSipHeaders($message);
        $callId = $headers['Call-ID'] ?? '';
        $cseq = $headers['CSeq'] ?? '';

        if (str_contains($cseq, 'INVITE')) {
            $this->handleInviteResponse($statusCode, $message, $headers, $callId, $addr);
        } else if (str_contains($cseq, 'BYE')) {
            $this->handleByeResponse($statusCode, $message, $headers, $callId, $addr);
        } else if (str_contains($cseq, 'MESSAGE')) {
            if ($statusCode !== 200) {
                $this->logger?->debug("[GSS] MESSAGE响应状态码非200: {$statusCode}, addr=" . json_encode($addr));
            } else {
                $this->logger?->debug('[GSS] [RX] MESSAGE 200 OK (Catalog查询确认) addr=' . json_encode($addr));
            }
        }
    }

    private function handleInviteResponse(int $statusCode, string $message, array $headers, string $callId, array $addr) : void
    {
        if ($statusCode === 200) {
            if (str_contains($message, "\r\n\r\n")) {
                $sdp = explode("\r\n\r\n", $message, 2)[1];
                if ($sdp !== '') {
                    $this->logger?->debug('[GSS] 设备SDP:\n' . substr($sdp, 0, 200));
                }
            }

            $toHeader = $headers['To'] ?? '';
            $toTag = '';
            if (str_contains($toHeader, 'tag=')) {
                $tp = explode('tag=', $toHeader);
                $toTag = trim(end($tp));
            }

            $this->updateChannelStreamingByCallid($callId, true, 1, false, $toTag);
            $this->sendAck($message, $addr);
        } else if ($statusCode === 100 || $statusCode === 180) {
            // 正常流程，不需要日志
        } else if ($statusCode === 486) {
            $this->logger?->debug('[GSS] ❌ INVITE 486 Busy Here - 设备忙，尝试发送BYE终止旧会话');
            $this->trySendByeOn486($callId, $headers, $addr);
            $this->releaseChannelByCallid($callId);
        } else if ($statusCode === 488) {
            $this->logger?->debug('[GSS] ❌ INVITE 488 Not Acceptable Here - 媒体格式不被接受（SDP协商失败）');
            $this->releaseChannelByCallid($callId);
        } else if ($statusCode >= 100 && $statusCode < 200) {
            // 1xx临时响应，等待最终响应，不释放资源
        } else {
            $this->logger?->debug("[GSS] ⚠️ INVITE {$statusCode} - 未知响应");
            $this->releaseChannelByCallid($callId);
        }
    }

    private function handleByeResponse(int $statusCode, string $message, array $headers, string $callId, array $addr) : void
    {
        if ($statusCode !== 200) {
            $this->logger?->debug("[GSS] ⚠️ BYE {$statusCode} - 未知响应");
        }
    }

    private function sendAck(string $inviteResponse, array $addr) : void
    {
        $headers = $this->parseSipHeaders($inviteResponse);

        $callId = $headers['Call-ID'] ?? '';
        $fromHeader = $headers['From'] ?? '';
        $toHeader = $headers['To'] ?? '';

        $branch = 'z9hG4bK' . random_int(100000000, 999999999);

        $ackMsg = "ACK sip:{$addr[0]}:{$addr[1]} SIP/2.0\r\n"
            . "Via: SIP/2.0/{$this->getSipTransport()} {$this->serverIp}:{$this->serverPort};rport;branch={$branch}\r\n"
            . "From: {$fromHeader}\r\n"
            . "To: {$toHeader}\r\n"
            . "Call-ID: {$callId}\r\n"
            . "CSeq: 1 ACK\r\n"
            . "Max-Forwards: 70\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n";

        try {
            $this->sendSipResponse($ackMsg, $addr);
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] 发送ACK失败: ' . $e->getMessage());
        }
    }

    private function sendByeForChannel(string $deviceIp, int $devicePort, string $channelId, string $callId, string $fromTag, string $toTag = '') : bool
    {
        if ($callId === '') return false;

        $branch = 'z9hG4bK' . random_int(100000000, 999999999);

        $toHeader = "<sip:{$channelId}@{$this->realm}>";
        if ($toTag !== '') {
            $toHeader = "<sip:{$channelId}@{$this->realm}>;tag={$toTag}";
        }

        $byeMsg = "BYE sip:{$channelId}@{$deviceIp}:{$devicePort} SIP/2.0\r\n"
            . "Via: SIP/2.0/{$this->getSipTransport()} {$this->serverIp}:{$this->serverPort};rport;branch={$branch}\r\n"
            . "From: <sip:{$this->serverId}@{$this->realm}>;tag={$fromTag}\r\n"
            . "To: {$toHeader}\r\n"
            . "Call-ID: {$callId}\r\n"
            . "CSeq: 2 BYE\r\n"
            . "Max-Forwards: 70\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n";

        try {
            $addr = [$deviceIp, $devicePort];
            $success = $this->sendSipResponse($byeMsg, $addr);
            if ($success) {
                $this->logger?->debug("[GSS] 📤 已发送BYE终止旧会话: {$channelId}, call_id=" . substr($callId, 0, 16) . '...');
            }
            return $success;
        } catch (\Throwable $e) {
            $this->logger?->error("[GSS] ❌ 发送BYE失败: {$channelId}, error=" . $e->getMessage());
            return false;
        }
    }

    private function trySendByeOn486(string $callId, array $headers, array $addr) : void
    {
        $oldCallId = '';
        $oldFromTag = '';
        $oldToTag = '';
        $foundChannelId = '';
        $deviceIp = $addr[0];
        $devicePort = $addr[1];

        $this->pendingInvitesLock->lock();
        foreach ($this->pendingInvites as $chId => $pending) {
            if (($pending['call_id'] ?? '') === $callId) {
                $oldCallId = $pending['old_call_id'] ?? '';
                $oldFromTag = $pending['old_from_tag'] ?? '';
                $oldToTag = $pending['old_to_tag'] ?? '';
                $foundChannelId = $chId;
                break;
            }
        }
        $this->pendingInvitesLock->unlock();

        if ($oldCallId === '' && $foundChannelId !== '') {
            $this->lock->lock();
            foreach ($this->devices as $device) {
                $channel = $this->findChannel($device->channels, $foundChannelId);
                if ($channel) {
                    if ($channel->fromTag !== '') $oldFromTag = $channel->fromTag;
                    if ($channel->toTag !== '') $oldToTag = $channel->toTag;
                    $deviceIp = $device->ip;
                    $devicePort = $device->port;
                    break;
                }
            }
            $this->lock->unlock();
        }

        if ($oldCallId !== '') {
            $this->logger?->debug("[GSS] 📤 486恢复：发送BYE终止旧会话: channel={$foundChannelId}, old_call_id=" . substr($oldCallId, 0, 16) . '...');
            $this->sendByeForChannel($deviceIp, (int)$devicePort, $foundChannelId, $oldCallId, $oldFromTag, $oldToTag);
        } else if ($foundChannelId !== '') {
            $this->logger?->debug("[GSS] ⚠️ 486恢复：无旧会话dialog信息，尝试发送通用BYE: channel={$foundChannelId}");
            $fromHeader = $headers['From'] ?? '';
            $fallbackFromTag = '';
            if (str_contains($fromHeader, 'tag=')) {
                $tp = explode('tag=', $fromHeader);
                $fallbackFromTag = trim(end($tp));
            }
            $this->sendByeForChannel($deviceIp, (int)$devicePort, $foundChannelId, $callId, $fallbackFromTag, '');
        }
    }

    private function releaseChannelByCallid(string $callId) : void
    {
        $channelIdToClean = null;
        $this->pendingInvitesLock->lock();
        foreach ($this->pendingInvites as $chId => $pending) {
            if (($pending['call_id'] ?? '') === $callId) {
                $channelIdToClean = $chId;
                break;
            }
        }
        $this->pendingInvitesLock->unlock();

        if ($channelIdToClean !== null) {
            $this->pendingInvitesLock->lock();
            $pending = $this->pendingInvites[$channelIdToClean] ?? null;
            unset($this->pendingInvites[$channelIdToClean]);
            $this->pendingInvitesLock->unlock();

            if ($pending) {
                $this->rtpPortMgr->release((int)$pending['rtp_port']);
                $this->closeRtpServer($channelIdToClean);
                $this->logger?->debug("[GSS] 🧹 清理pending INVITE: {$channelIdToClean}");

                $this->lock->lock();
                $device = $this->devices[$pending['device_id']] ?? null;
                if ($device) {
                    $channel = $this->findChannel($device->channels, $channelIdToClean);
                    if ($channel) {
                        $channel->rtpPort = 0;
                        $channel->allocatedRtpPort = 0;
                        $channel->callId = '';
                        $channel->fromTag = '';
                        $channel->toTag = '';
                        $channel->streaming = false;
                        $channel->forwardState = 0;
                        $channel->inviting = false;
                        $this->logger?->debug("[GSS] ✓ 已重置通道状态: {$channelIdToClean}");
                    }
                }
                $this->lock->unlock();
            }
        }

        $this->lock->lock();
        foreach ($this->devices as $device) {
            foreach ($device->channels as $channel) {
                if ($channel->callId === $callId) {
                    $channelId = $channel->channelId;
                    $rtpPort = $channel->allocatedRtpPort;
                    if ($rtpPort > 0) {
                        $this->rtpPortMgr->release($rtpPort);
                    }
                    if ($channelId !== '') {
                        $this->closeRtpServer($channelId);
                    }
                    $channel->rtpPort = 0;
                    $channel->allocatedRtpPort = 0;
                    $channel->callId = '';
                    $channel->fromTag = '';
                    $channel->toTag = '';
                    $channel->streaming = false;
                    $channel->forwardState = 0;
                    $channel->inviting = false;
                    $this->lock->unlock();
                    return;
                }
            }
        }
        $this->lock->unlock();
    }

    private function updateChannelStreamingByCallid(string $callId, bool $streaming = false, int $forwardState = 0, ?bool $inviting = null, ?string $toTag = null) : void
    {
        $this->lock->lock();
        try {
            foreach ($this->devices as $device) {
                foreach ($device->channels as $channel) {
                    if ($channel->callId === $callId) {
                        $channel->streaming = $streaming;
                        $channel->forwardState = $forwardState;
                        if ($inviting !== null) $channel->inviting = $inviting;
                        if ($toTag !== null) $channel->toTag = $toTag;
                        return;
                    }
                }
            }
        } finally {
            $this->lock->unlock();
        }
    }

    // -------------------------------------------------------------
    // SIP 响应构建
    // -------------------------------------------------------------

    private function send401Unauthorized(string $request, array $addr) : void
    {
        $nonce = $this->generateNonce();

        $response = "SIP/2.0 401 Unauthorized\r\n"
            . 'Via: ' . $this->getHeader($request, 'Via') . "\r\n"
            . 'From: ' . $this->getHeader($request, 'From') . "\r\n"
            . 'To: ' . $this->getHeader($request, 'To') . "\r\n"
            . 'CSeq: ' . $this->getHeader($request, 'CSeq') . "\r\n"
            . 'Call-ID: ' . $this->getHeader($request, 'Call-ID') . "\r\n"
            . "WWW-Authenticate: Digest realm=\"{$this->realm}\", nonce=\"{$nonce}\", algorithm=MD5, qop=\"auth\"\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n";

        $this->sendSipResponse($response, $addr);
    }

    private function send200Ok(string $request, array $addr, ?Device $device) : void
    {
        $toHeader = $this->getHeader($request, 'To');

        if (!str_contains($toHeader, ';tag=')) {
            $tag = (string)random_int(10000000, 99999999);
            $toHeader = str_replace('>', ";tag={$tag}>", $toHeader);
        }

        $response = "SIP/2.0 200 OK\r\n"
            . 'Via: ' . $this->getHeader($request, 'Via') . "\r\n"
            . 'From: ' . $this->getHeader($request, 'From') . "\r\n"
            . "To: {$toHeader}\r\n"
            . 'CSeq: ' . $this->getHeader($request, 'CSeq') . "\r\n"
            . 'Call-ID: ' . $this->getHeader($request, 'Call-ID') . "\r\n"
            . "Contact: <sip:{$this->serverId}@{$this->serverIp}:{$this->serverPort}>\r\n"
            . "Expires: {$this->sipServerExpiry}\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n";

        $this->sendSipResponse($response, $addr);
    }

    private function send100Trying(string $request, array $addr) : void
    {
        $response = "SIP/2.0 100 Trying\r\n"
            . 'Via: ' . $this->getHeader($request, 'Via') . "\r\n"
            . 'From: ' . $this->getHeader($request, 'From') . "\r\n"
            . 'To: ' . $this->getHeader($request, 'To') . "\r\n"
            . 'CSeq: ' . $this->getHeader($request, 'CSeq') . "\r\n"
            . 'Call-ID: ' . $this->getHeader($request, 'Call-ID') . "\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n";

        $this->sendSipResponse($response, $addr);
    }

    /** @return array{0:string,1:array<string>} [mediaLine, attrs] */
    private function buildSdpMediaLine(string $mediaType, int $port, array $payloadTypes) : array
    {
        $attrs = [];

        if ($this->rtpTransferMode === 0) {
            $mediaLine = "m={$mediaType} {$port} RTP/AVP " . implode(' ', $payloadTypes);
        } else if ($this->rtpTransferMode === 1) {
            $mediaLine = "m={$mediaType} {$port} TCP/RTP/AVP " . implode(' ', $payloadTypes);
            $attrs[] = 'a=setup:passive';
            $attrs[] = 'a=connection:new';
        } else {
            $mediaLine = "m={$mediaType} {$port} RTP/AVP " . implode(' ', $payloadTypes);
        }

        return [$mediaLine, $attrs];
    }

    private function buildSdp(string $mediaType, int $port, array $payloadTypes, array $attrsMap, bool $includeAudio = false) : string
    {
        [$mediaLine, $tcpAttrs] = $this->buildSdpMediaLine($mediaType, $port, $payloadTypes);

        $sdp = "v=0\r\n"
            . "o={$this->serverId} 0 0 IN IP4 {$this->serverIp}\r\n"
            . "s=Play\r\n"
            . "c=IN IP4 {$this->serverIp}\r\n"
            . "t=0 0\r\n"
            . "{$mediaLine}\r\n";

        foreach ($tcpAttrs as $attr) {
            $sdp .= "{$attr}\r\n";
        }

        foreach ($attrsMap as $pt => $rtpmap) {
            $sdp .= "a=rtpmap:{$pt} {$rtpmap}\r\n";
        }

        $sdp .= "a=recvonly\r\n";

        if ($includeAudio) {
            $audioPayloads = [8, 0, 9, 18, 97, 98];
            [$audioMediaLine, $audioTcpAttrs] = $this->buildSdpMediaLine('audio', $port, $audioPayloads);
            $sdp .= "{$audioMediaLine}\r\n";
            foreach ($audioTcpAttrs as $attr) {
                $sdp .= "{$attr}\r\n";
            }
            $sdp .= "a=recvonly\r\n";
            $sdp .= "a=rtpmap:8 PCMA/8000\r\n";
            $sdp .= "a=rtpmap:0 PCMU/8000\r\n";
            $sdp .= "a=rtpmap:9 G722/16000\r\n";
            $sdp .= "a=rtpmap:18 G729/8000\r\n";
            $sdp .= "a=rtpmap:97 G726-32/8000\r\n";
            $sdp .= "a=rtpmap:98 mpeg4-generic/8000/1\r\n";
            $sdp .= "a=fmtp:98 profile-level-id=1;mode=AAC-hbr;config=1210;sizeLength=13;indexLength=3;indexDeltaLength=3\r\n";
            $sdp .= "a=ssrc:87654321\r\n";
            $sdp .= "a=y:0100000002\r\n";
        }

        $sdp .= "a=ssrc:12345678\r\n";
        $sdp .= "a=y:0100000001\r\n";

        return $sdp;
    }

    private function send200OkInvite(string $request, array $addr, array $headers) : void
    {
        $includeAudio = ($this->rtpTransferAudioType === 1);

        $sdp = $this->buildSdp(
            'video', 0, [96, 98, 97],
            [96 => 'PS/90000', 98 => 'H264/90000', 97 => 'MPEG4/90000'],
            $includeAudio
        );

        $toHeader = $this->getHeader($request, 'To');
        if (!str_contains($toHeader, ';tag=')) {
            $tag = (string)random_int(10000000, 99999999);
            $toHeader = str_replace('>', ";tag={$tag}>", $toHeader);
        }

        $response = "SIP/2.0 200 OK\r\n"
            . 'Via: ' . $this->getHeader($request, 'Via') . "\r\n"
            . 'From: ' . $this->getHeader($request, 'From') . "\r\n"
            . "To: {$toHeader}\r\n"
            . 'CSeq: ' . $this->getHeader($request, 'CSeq') . "\r\n"
            . 'Call-ID: ' . $this->getHeader($request, 'Call-ID') . "\r\n"
            . "Contact: <sip:{$this->serverId}@{$this->serverIp}:{$this->serverPort}>\r\n"
            . "Content-Type: application/sdp\r\n"
            . 'Content-Length: ' . strlen($sdp) . "\r\n"
            . "\r\n"
            . $sdp;

        $this->sendSipResponse($response, $addr);
    }

    private function sendResponse(string $request, array $addr, int $code, string $reason) : void
    {
        $response = "SIP/2.0 {$code} {$reason}\r\n"
            . 'Via: ' . $this->getHeader($request, 'Via') . "\r\n"
            . 'From: ' . $this->getHeader($request, 'From') . "\r\n"
            . 'To: ' . $this->getHeader($request, 'To') . "\r\n"
            . 'CSeq: ' . $this->getHeader($request, 'CSeq') . "\r\n"
            . 'Call-ID: ' . $this->getHeader($request, 'Call-ID') . "\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n";
        $this->sendSipResponse($response, $addr);
    }

    private function send200OkMessage(string $request, array $addr) : void
    {
        $response = "SIP/2.0 200 OK\r\n"
            . 'Via: ' . $this->getHeader($request, 'Via') . "\r\n"
            . 'From: ' . $this->getHeader($request, 'From') . "\r\n"
            . 'To: ' . $this->getHeader($request, 'To') . "\r\n"
            . 'CSeq: ' . $this->getHeader($request, 'CSeq') . "\r\n"
            . 'Call-ID: ' . $this->getHeader($request, 'Call-ID') . "\r\n"
            . "Content-Length: 0\r\n"
            . "\r\n";

        $this->sendSipResponse($response, $addr);
    }

    private function getHeader(string $message, string $headerName) : string
    {
        $lines = explode("\r\n", $message);
        $needle = strtolower($headerName) . ':';
        for ($i = 1; $i < count($lines); $i++) {
            if (str_starts_with(strtolower($lines[$i]), $needle)) {
                return trim(substr($lines[$i], strpos($lines[$i], ':') + 1));
            }
        }
        return '';
    }

    private function verifyAuth(string $authHeader, string $uri, string $method, string $deviceId = '') : bool
    {
        if (str_starts_with($authHeader, 'Digest ')) {
            $authHeader = substr($authHeader, 7);
        }

        $authParams = [];
        if (preg_match_all('/(\w+)="([^"]*)"|(\w+)=(\w+)/', $authHeader, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = strtolower($match[1] !== '' ? $match[1] : $match[3]);
                $value = $match[2] !== '' || $match[1] !== '' ? $match[2] : $match[4];
                $authParams[$key] = $value;
            }
        }

        $username = $authParams['username'] ?? '';
        $realm = $authParams['realm'] ?? '';
        $nonce = $authParams['nonce'] ?? '';
        $response = $authParams['response'] ?? '';
        $uriVal = $authParams['uri'] ?? '';
        $qop = trim($authParams['qop'] ?? '', '"');
        $cnonce = $authParams['cnonce'] ?? '';
        $nc = $authParams['nc'] ?? '';

        if ($username === '' || $realm === '' || $nonce === '' || $response === '' || $uriVal === '') {
            $this->logger?->error('[GSS] 认证参数不完整');
            $this->logger?->debug('[GSS] 解析结果: ' . json_encode($authParams));
            return false;
        }

        $expectedResponse = $this->calculateResponse($username, $realm, $this->password, $nonce, $method, $uriVal, $qop, $cnonce, $nc);

        $this->lock->lock();
        $device = $this->devices[$deviceId] ?? null;
        $isFirstRegister = !isset($this->devices[$deviceId]) || ($device && !$device->registered);
        $this->lock->unlock();

        if ($isFirstRegister) {
            $this->logger?->debug('[GSS] 🔐 认证参数详情:');
            $this->logger?->debug("[GSS]   设备用户名: {$username}");
            $this->logger?->debug("[GSS]   Realm: {$realm}");
            $this->logger?->debug("[GSS]   服务器密码: {$this->password}");
            $this->logger?->debug("[GSS]   qop: {$qop}, nc: {$nc}, cnonce: {$cnonce}");
            $this->logger?->debug("[GSS]   设备返回: {$response}");
            $this->logger?->debug("[GSS]   服务器计算: {$expectedResponse}");
        }

        if (hash_equals($expectedResponse, $response)) {
            return true;
        }

        $this->logger?->error('[GSS] ❌ 认证失败: 密码错误或计算方式不匹配');
        return false;
    }

    private function extractReceivedIp(string $message) : string
    {
        if (preg_match('/Via:\s+SIP\/2\.0\/\w+\s+[^;]+;received=([\d.]+)/', $message, $m)) {
            return $m[1];
        }
        return '';
    }

    private function extractRport(string $message) : int
    {
        foreach (explode("\r\n", $message) as $line) {
            if (str_starts_with($line, 'Via:')) {
                if (preg_match('/rport=(\d+)/', $line, $m)) {
                    return (int)$m[1];
                }
                break;
            }
        }
        return -1;
    }

    private function updateDeviceContact(string $deviceId, string $newIp, int $newPort, string $message = '') : bool
    {
        $this->lock->lock();
        try {
            $device = $this->devices[$deviceId] ?? null;
            if (!$device) return false;

            if ($message !== '') {
                $receivedIp = $this->extractReceivedIp($message);
                $rport = $this->extractRport($message);
                if ($receivedIp !== '' && $rport > 0) {
                    $newIp = $receivedIp;
                    $newPort = $rport;
                }
            }

            if ($device->ip === $newIp && $device->port === $newPort) {
                return false;
            }

            $oldIp = $device->ip;
            $oldPort = $device->port;
            $device->ip = $newIp;
            $device->port = $newPort;

            $this->logger?->debug("[GSS]   地址更新: {$oldIp}:{$oldPort} -> {$newIp}:{$newPort}");

            return true;
        } finally {
            $this->lock->unlock();
        }
    }

    private function checkExpiredDevices() : void
    {
        $nowMs = (int)(microtime(true) * 1000);
        $expiredTimeout = max($this->sipServerTimeout, 180) * 1000;
        $registerExpiryMs = max($this->sipServerExpiry, 600) * 1000;
        $cleanupTimeout = 1800000; // 30分钟无活动则清理设备

        $expiredDevicesInfo = []; // [device_id => channelsToStop[]]
        $devicesToCleanup = [];

        $this->lock->lock();
        $expiredDevices = [];
        foreach ($this->devices as $deviceId => $device) {
            if ($device->registered) {
                $hasRecentKeepalive = false;
                if ($device->lastKeepaliveTime > 0) {
                    $devDiff = $nowMs - $device->lastKeepaliveTime;
                    if ($devDiff < $expiredTimeout) $hasRecentKeepalive = true;
                }

                if (!$hasRecentKeepalive) {
                    foreach ($device->channels as $channel) {
                        if ($channel->lastKeepaliveTime > 0) {
                            $timeDiff = $nowMs - $channel->lastKeepaliveTime;
                            if ($timeDiff < $expiredTimeout) {
                                $hasRecentKeepalive = true;
                                break;
                            }
                        }
                    }
                }

                if (!$hasRecentKeepalive) {
                    if ($device->lastRegisterTime > 0) {
                        $regDiff = $nowMs - $device->lastRegisterTime;
                        if ($regDiff > $registerExpiryMs) {
                            $expiredDevices[] = $deviceId;
                        }
                    }
                }
            } else {
                if ($device->lastRegisterTime > 0) {
                    $offlineDuration = $nowMs - $device->lastRegisterTime;
                    if ($offlineDuration > $cleanupTimeout) {
                        $devicesToCleanup[] = $deviceId;
                        $this->logger?->debug("[GSS] 🧹 清理长时间离线设备: {$deviceId} (离线" . intdiv($offlineDuration, 60000) . "分钟)");
                    }
                }
            }
        }

        foreach ($expiredDevices as $deviceId) {
            $device = $this->devices[$deviceId];
            $channelsToStop = [];
            foreach ($device->channels as $channel) {
                if ($channel->streaming) {
                    $channelsToStop[] = ['channel_id' => $channel->channelId, 'rtp_port' => $channel->allocatedRtpPort];
                }
            }
            $expiredDevicesInfo[] = [$deviceId, $channelsToStop];

            $device->registered = false;
            foreach ($device->channels as $channel) {
                $channel->streaming = false;
                $channel->rtpPort = 0;
                $channel->allocatedRtpPort = 0;
                $channel->callId = '';
                $channel->forwardState = 0;
                $channel->inviting = false;
            }
        }

        foreach ($devicesToCleanup as $deviceId) {
            $device = $this->devices[$deviceId] ?? null;
            unset($this->devices[$deviceId]);
            if ($device) {
                foreach ($device->channels as $channel) {
                    if ($channel->streaming || $channel->allocatedRtpPort > 0) {
                        $this->closeRtpServer($channel->channelId);
                        if ($channel->allocatedRtpPort > 0) {
                            $this->rtpPortMgr->release($channel->allocatedRtpPort);
                        }
                    }
                }
            }
        }
        $this->lock->unlock();

        // 第二阶段：锁外执行（避免持锁阻塞IO）
        foreach ($expiredDevicesInfo as [$deviceId, $channelsToStop]) {
            $this->logger?->debug("[GSS] ⚠️ 设备 {$deviceId} 心跳超时，标记为离线");
            foreach ($channelsToStop as $chInfo) {
                $this->logger?->debug("[GSS] ⏹️ 停止过期设备的推流: {$chInfo['channel_id']}");
                $this->closeRtpServer($chInfo['channel_id']);
                $this->rtpPortMgr->release((int)$chInfo['rtp_port']);
            }
        }

        if ($expiredDevicesInfo) {
            $this->logger?->debug('[GSS] 发现 ' . count($expiredDevicesInfo) . ' 个过期设备');
        }
    }

    public function logStatus() : void
    {
        $this->logger?->debug('[GSS] -----------GB28181SipServer.log_status start----------');

        $this->lock->lock();
        $devicesSnapshot = array_values($this->devices);
        $devicesCount = count($this->devices);
        $this->lock->unlock();

        $this->logger?->debug("[GSS] 服务器: {$this->serverIp}:{$this->serverPort}");
        $this->logger?->debug("[GSS] 已注册设备: {$devicesCount}");
        $this->logger?->debug('[GSS] 统计信息:');
        $this->logger?->debug('[GSS]   - 总注册数: ' . $this->stats['total_registers']);
        $this->logger?->debug('[GSS]   - 总邀请数: ' . $this->stats['total_invites']);
        $this->logger?->debug('[GSS]   - 总消息数: ' . $this->stats['total_messages']);

        if ($devicesSnapshot) {
            $this->logger?->debug('[GSS] 设备列表:');
            foreach ($devicesSnapshot as $device) {
                $status = $device->registered ? '在线' : '离线';
                $this->logger?->debug("[GSS]   - {$device->deviceId}: {$device->ip}:{$device->port} ({$status}) 注册于 {$device->registerTime}");
            }
        }

        $this->logger?->debug('[GSS] -----------GB28181SipServer.log_status end----------');
    }

    // -------------------------------------------------------------
    // 主动发送 INVITE（自动推流，收到 Catalog 后调用）
    // -------------------------------------------------------------

    public function sendInvite(string $deviceId, string $channelId) : bool
    {
        $this->lock->lock();
        if (!isset($this->devices[$deviceId])) {
            $this->logger?->error("[GSS] 设备 {$deviceId} 不存在");
            $this->lock->unlock();
            return false;
        }

        $device = $this->devices[$deviceId];
        if (!$device->registered) {
            $this->logger?->error("[GSS] 设备 {$deviceId} 未注册");
            $this->lock->unlock();
            return false;
        }

        $deviceIp = $device->ip;
        $devicePort = $device->port;

        $channel = $this->findChannel($device->channels, $channelId);
        if ($channel && ($channel->streaming || $channel->forwardState === 1 || $channel->inviting)) {
            $this->logger?->debug("[GSS] 通道 {$channelId} 已在推流中或正在INVITE，跳过重复请求");
            $this->lock->unlock();
            return false;
        }

        if ($channel) {
            $channel->inviting = true;
        }
        $this->lock->unlock();

        $rtpPort = $this->rtpPortMgr->allocate($channelId);
        $this->logger?->debug("[GSS] 🔧 send_invite分配RTP端口: {$rtpPort}, channel_id={$channelId}");
        if ($rtpPort === 0) {
            $this->logger?->error("[GSS] RTP端口分配失败: {$channelId}");
            $this->lock->lock();
            $device = $this->devices[$deviceId] ?? null;
            if ($device) {
                $channel = $this->findChannel($device->channels, $channelId);
                if ($channel) $channel->inviting = false;
            }
            $this->lock->unlock();
            return false;
        }

        $zlmPort = 0;
        try {
            [$success, $msg, $zlmPort] = $this->zlm->openRtpServer($rtpPort, $this->rtpTransferMode === 1 ? 2 : 0, $channelId);
            if (!$success) {
                if (str_contains((string)$msg, 'already exists')) {
                    $this->logger?->debug("[GSS] ⚠️ RTP服务器已存在，先关闭再重试: {$channelId}");
                    $this->closeRtpServer($channelId);
                    Coroutine::sleep(0.5);

                    [$success, $msg, $zlmPort] = $this->zlm->openRtpServer($rtpPort, $this->rtpTransferMode === 1 ? 2 : 0, $channelId);

                    if (!$success) {
                        $this->logger?->error("[GSS] ZLM openRtpServer重试失败: {$msg}");
                        $this->rtpPortMgr->release($rtpPort);
                        $this->lock->lock();
                        $device = $this->devices[$deviceId] ?? null;
                        if ($device) {
                            $channel = $this->findChannel($device->channels, $channelId);
                            if ($channel) $channel->inviting = false;
                        }
                        $this->lock->unlock();
                        return false;
                    }
                } else {
                    $this->logger?->error("[GSS] ZLM openRtpServer失败: {$msg}");
                    $this->rtpPortMgr->release($rtpPort);
                    $this->lock->lock();
                    $device = $this->devices[$deviceId] ?? null;
                    if ($device) {
                        $channel = $this->findChannel($device->channels, $channelId);
                        if ($channel) $channel->inviting = false;
                    }
                    $this->lock->unlock();
                    return false;
                }
            }
            $this->logger?->debug("[GSS] ✓ RTP端口分配: {$rtpPort}, ZLM实际端口: {$zlmPort}, stream_id={$channelId}");
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] ZLM openRtpServer异常: ' . $e->getMessage());
            $this->rtpPortMgr->release($rtpPort);
            $this->lock->lock();
            $device = $this->devices[$deviceId] ?? null;
            if ($device) {
                $channel = $this->findChannel($device->channels, $channelId);
                if ($channel) $channel->inviting = false;
            }
            $this->lock->unlock();
            return false;
        }

        $callId = str_replace('-', '', gb_uuid4());
        $fromTag = (string)random_int(100000000, 999999999);
        $branch = 'z9hG4bK' . random_int(100000000, 999999999);
        $subject = "{$channelId}:0,{$this->serverId}:0";

        $includeAudio = ($this->rtpTransferAudioType === 1);
        $finalPort = $zlmPort > 0 ? $zlmPort : $rtpPort;
        $sdp = $this->buildSdp('video', $finalPort, [96, 98, 97],
            [96 => 'PS/90000', 98 => 'H264/90000', 97 => 'MPEG4/90000'], $includeAudio);

        $invite = "INVITE sip:{$channelId}@{$deviceIp}:{$devicePort} SIP/2.0\r\n"
            . "Via: SIP/2.0/{$this->getSipTransport()} {$this->serverIp}:{$this->serverPort};rport;branch={$branch}\r\n"
            . "From: <sip:{$this->serverId}@{$this->realm}>;tag={$fromTag}\r\n"
            . "To: <sip:{$channelId}@{$this->realm}>\r\n"
            . "Call-ID: {$callId}\r\n"
            . "CSeq: 1 INVITE\r\n"
            . "Max-Forwards: 70\r\n"
            . "Contact: <sip:{$this->serverId}@{$this->serverIp}:{$this->serverPort}>\r\n"
            . "User-Agent: rebucca\r\n"
            . "Subject: {$subject}\r\n"
            . "Session-Expires: {$this->sipServerTimeout};refresher=uas\r\n"
            . "Supported: timer\r\n"
            . "Content-Type: application/sdp\r\n"
            . 'Content-Length: ' . strlen($sdp) . "\r\n"
            . "\r\n"
            . $sdp;

        $this->pendingInvitesLock->lock();
        $this->pendingInvites[$channelId] = ['rtp_port' => $rtpPort, 'call_id' => $callId, 'device_id' => $deviceId];
        $this->pendingInvitesLock->unlock();

        try {
            $addr = [$deviceIp, $devicePort];
            $this->sendSipResponse($invite, $addr);

            $this->lock->lock();
            $device = $this->devices[$deviceId] ?? null;
            if ($device) {
                $channel = $this->findChannel($device->channels, $channelId);
                if ($channel) {
                    $channel->rtpPort = $finalPort;
                    $channel->allocatedRtpPort = $rtpPort;
                    $channel->callId = $callId;
                    $channel->fromTag = $fromTag;
                    $this->logger?->debug("[GSS] ✓ INVITE已发送，等待200 OK响应: {$channelId}");
                } else {
                    $this->logger?->debug("[GSS] ⚠️ 通道 {$channelId} 在INVITE发送后不在内存中，pending状态已保留");
                    $this->lock->unlock();
                    return true;
                }
            } else {
                $this->logger?->debug("[GSS] ⚠️ 设备 {$deviceId} 在INVITE发送后已不存在，等待catalog刷新恢复");
                $this->lock->unlock();
                return true;
            }
            $this->lock->unlock();

            $this->logger?->debug("[GSS] 📡 INVITE已发送: {$channelId} -> {$deviceIp}:{$devicePort}, RTP port={$rtpPort}");
            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] ✗ 发送INVITE失败: ' . $e->getMessage());
            $this->rtpPortMgr->release($rtpPort);
            $this->closeRtpServer($channelId);
            $this->lock->lock();
            $device = $this->devices[$deviceId] ?? null;
            if ($device) {
                $channel = $this->findChannel($device->channels, $channelId);
                if ($channel) {
                    $channel->inviting = false;
                    $channel->rtpPort = 0;
                    $channel->allocatedRtpPort = 0;
                    $channel->callId = '';
                    $channel->streaming = false;
                    $channel->forwardState = 0;
                }
            }
            $this->lock->unlock();
            return false;
        }
    }

    public function queryDeviceInfo(string $deviceId) : bool
    {
        $this->lock->lock();
        if (!isset($this->devices[$deviceId])) {
            $this->lock->unlock();
            return false;
        }
        $device = $this->devices[$deviceId];
        $deviceIp = $device->ip;
        $devicePort = $device->port;
        $this->lock->unlock();

        $callId = str_replace('-', '', gb_uuid4());
        $fromTag = (string)random_int(100000000, 999999999);
        $branch = 'z9hG4bK' . random_int(100000000, 999999999);
        $sn = random_int(1, 9999);

        $body = "<?xml version=\"1.0\"?>\r\n<Query>\r\n<CmdType>DeviceInfo</CmdType>\r\n<SN>{$sn}</SN>\r\n<DeviceID>{$deviceId}</DeviceID>\r\n</Query>\r\n";

        $msg = "MESSAGE sip:{$deviceId}@{$deviceIp}:{$devicePort} SIP/2.0\r\n"
            . "Via: SIP/2.0/{$this->getSipTransport()} {$this->serverIp}:{$this->serverPort};rport;branch={$branch}\r\n"
            . "From: <sip:{$this->serverId}@{$this->realm}>;tag={$fromTag}\r\n"
            . "To: <sip:{$deviceId}@{$this->realm}>\r\n"
            . "Call-ID: {$callId}\r\n"
            . "CSeq: 1 MESSAGE\r\n"
            . "Max-Forwards: 70\r\n"
            . "Contact: <sip:{$this->serverId}@{$this->serverIp}:{$this->serverPort}>\r\n"
            . "Content-Type: Application/MANSCDP+xml\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        try {
            $addr = [$deviceIp, $devicePort];
            $this->sendSipResponse($msg, $addr);
            $this->logger?->debug("[GSS] [DEVICEINFO] 已发送DeviceInfo查询到设备 {$deviceId} @ {$deviceIp}:{$devicePort}");
            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] [DEVICEINFO] 发送DeviceInfo查询失败: ' . $e->getMessage());
            return false;
        }
    }

    public function queryCatalog(string $deviceId) : bool
    {
        $this->lock->lock();
        if (!isset($this->devices[$deviceId])) {
            $this->lock->unlock();
            return false;
        }
        $device = $this->devices[$deviceId];
        $deviceIp = $device->ip;
        $devicePort = $device->port;
        $this->lock->unlock();

        $callId = str_replace('-', '', gb_uuid4());
        $fromTag = (string)random_int(100000000, 999999999);
        $branch = 'z9hG4bK' . random_int(100000000, 999999999);
        $sn = random_int(1, 9999);

        $body = "<?xml version=\"1.0\"?>\r\n<Query>\r\n<CmdType>Catalog</CmdType>\r\n<SN>{$sn}</SN>\r\n<DeviceID>{$deviceId}</DeviceID>\r\n</Query>\r\n";

        $msg = "MESSAGE sip:{$deviceId}@{$deviceIp}:{$devicePort} SIP/2.0\r\n"
            . "Via: SIP/2.0/{$this->getSipTransport()} {$this->serverIp}:{$this->serverPort};rport;branch={$branch}\r\n"
            . "From: <sip:{$this->serverId}@{$this->realm}>;tag={$fromTag}\r\n"
            . "To: <sip:{$deviceId}@{$this->realm}>\r\n"
            . "Call-ID: {$callId}\r\n"
            . "CSeq: 1 MESSAGE\r\n"
            . "Max-Forwards: 70\r\n"
            . "Contact: <sip:{$this->serverId}@{$this->serverIp}:{$this->serverPort}>\r\n"
            . "Content-Type: Application/MANSCDP+xml\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        try {
            $addr = [$deviceIp, $devicePort];
            $this->sendSipResponse($msg, $addr);
            $this->logger?->debug("[GSS] [CATALOG] 已发送Catalog查询到设备 {$deviceId} @ {$deviceIp}:{$devicePort}");
            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] [CATALOG] 发送Catalog查询失败: ' . $e->getMessage());
            return false;
        }
    }

    private function getCatalogLock(string $deviceId) : CoLock
    {
        $this->catalogLocksGuard->lock();
        try {
            if (!isset($this->catalogLocks[$deviceId])) {
                $this->catalogLocks[$deviceId] = new CoLock();
            }
            return $this->catalogLocks[$deviceId];
        } finally {
            $this->catalogLocksGuard->unlock();
        }
    }

    public function removeChannel(string $channelId) : void
    {
        if ($channelId === '') return;
        $removedCount = 0;
        $this->lock->lock();
        $removeFn = function (array $channels) use (&$removeFn, &$removedCount, $channelId) : array {
            $kept = [];
            foreach ($channels as $ch) {
                if ($ch->channelId === $channelId) {
                    $removedCount++;
                    continue;
                }
                if ($ch->children) {
                    $ch->children = $removeFn($ch->children);
                }
                $kept[] = $ch;
            }
            return $kept;
        };
        foreach ($this->devices as $device) {
            $device->channels = $removeFn($device->channels);
        }
        $this->lock->unlock();

        if ($removedCount > 0) {
            $this->logger?->debug("[GSS] 已从内存清理通道: {$channelId} (共{$removedCount}处)");
        }
    }

    /** @return Channel[] */
    public function parseCatalogResponse(string $body, string $deviceId, string $parentId = '') : array
    {
        try {
            $root = gb_safe_xml_parse($body);
            $itemList = gb_xml_find_all($root, 'Item');

            if (!$itemList) {
                $this->logger?->debug('[GSS] ⚠️ Catalog响应中没有通道项');
                return [];
            }

            $catalogLock = $this->getCatalogLock($deviceId);
            $catalogLock->lock();
            try {
                $channels = [];
                $seenChannelIds = [];

                $this->lock->lock();
                $device = $this->devices[$deviceId] ?? null;
                $this->lock->unlock();

                $oldChannelMap = [];
                if ($device) {
                    $buildChMap = function (array $chs) use (&$buildChMap) : array {
                        $m = [];
                        foreach ($chs as $ch) {
                            $m[$ch->channelId] = $ch;
                            if ($ch->children) {
                                $m += $buildChMap($ch->children);
                            }
                        }
                        return $m;
                    };
                    $this->lock->lock();
                    $oldChannelMap = $buildChMap($device->channels);
                    $this->lock->unlock();
                }

                foreach ($itemList as $item) {
                    $chIdElem = gb_xml_child($item, 'DeviceID');
                    if ($chIdElem === null) continue;

                    $chId = gb_xml_text($chIdElem);
                    if (isset($seenChannelIds[$chId])) {
                        $this->logger?->debug("[GSS] ⚠️ Catalog响应含重复通道ID {$chId}，已跳过");
                        continue;
                    }
                    $seenChannelIds[$chId] = true;

                    $name = gb_xml_text(gb_xml_child($item, 'Name'));
                    $statusText = gb_xml_text(gb_xml_child($item, 'Status'));
                    $status = $statusText !== '' ? $statusText : 'OFF';
                    $parentalText = gb_xml_text(gb_xml_child($item, 'Parental'));
                    $parental = $parentalText !== '' ? (int)$parentalText : 0;
                    $parentIdText = gb_xml_text(gb_xml_child($item, 'ParentID'));
                    $pid = $parentIdText !== '' ? $parentIdText : ($parentId !== '' ? $parentId : $deviceId);
                    $devType = gb_xml_text(gb_xml_child($item, 'DeviceType'));
                    $manufacturer = gb_xml_text(gb_xml_child($item, 'Manufacturer'));
                    $model = gb_xml_text(gb_xml_child($item, 'Model'));
                    $owner = gb_xml_text(gb_xml_child($item, 'Owner'));
                    $civilCode = gb_xml_text(gb_xml_child($item, 'CivilCode'));
                    $sumNumText = gb_xml_text(gb_xml_child($item, 'SumNum'));
                    $sumNum = $sumNumText !== '' ? (int)$sumNumText : 0;

                    $channel = new Channel($chId, $name, $device ? : null, $this->logger);
                    $channel->status = $status;
                    $channel->parental = $parental;
                    $channel->parentId = $pid;
                    $channel->deviceType = $devType;
                    $channel->manufacturer = $manufacturer;
                    $channel->model = $model;
                    $channel->owner = $owner;
                    $channel->civilCode = $civilCode;
                    $channel->sumNum = $sumNum;
                    $channel->lastRegisterTime = $device ? (int)(microtime(true) * 1000) : 0;

                    if ($parental === 1) {
                        $this->logger?->debug("[GSS]   📁 目录: {$chId} ({$name})");
                        Coroutine::create(function () use ($deviceId, $chId) {
                            $this->querySubCatalog($deviceId, $chId);
                        });
                    } else {
                        $icons = ['IPC' => '📷', 'DVR' => '📼', 'NVR' => '🖥️'];
                        $typeIcon = $icons[$devType] ?? '📹';
                        $this->logger?->debug("[GSS]   {$typeIcon} 通道: {$chId} ({$name}) [{$status}]");

                        if ($this->adminHost && !isset($oldChannelMap[$chId])) {
                            Coroutine::create(function () use ($channel) {
                                $channel->updateAdmin($this);
                            });
                        }
                    }

                    $channels[] = $channel;
                }

                if ($parentId !== '') {
                    $this->lock->lock();
                    $device = $this->devices[$deviceId] ?? null;
                    if ($device) {
                        $parentCh = $this->findChannel($device->channels, $parentId);
                        if ($parentCh) {
                            $oldChildren = $parentCh->children ? : [];

                            $buildChildMap = function (array $oldChannels) use (&$buildChildMap) : array {
                                $chMap = [];
                                foreach ($oldChannels as $ch) {
                                    $chMap[$ch->channelId] = $ch;
                                    if ($ch->children) $chMap += $buildChildMap($ch->children);
                                }
                                return $chMap;
                            };
                            $oldChildMap = $buildChildMap($oldChildren);

                            $preserveChildStates = function (array $newChannels) use (&$preserveChildStates, $oldChildMap) : void {
                                foreach ($newChannels as $newCh) {
                                    if (isset($oldChildMap[$newCh->channelId])) {
                                        $oldCh = $oldChildMap[$newCh->channelId];
                                        $newCh->streaming = $oldCh->streaming;
                                        $newCh->rtpPort = $oldCh->rtpPort;
                                        $newCh->allocatedRtpPort = $oldCh->allocatedRtpPort;
                                        $newCh->callId = $oldCh->callId;
                                        $newCh->fromTag = $oldCh->fromTag;
                                        $newCh->toTag = $oldCh->toTag;
                                        $newCh->inviting = false;
                                        $newCh->forwardState = $oldCh->forwardState;
                                    }
                                    if ($newCh->children) $preserveChildStates($newCh->children);
                                }
                            };
                            $preserveChildStates($channels);

                            $parentCh->children = $channels;
                            $this->logger?->debug("[GSS]   ↳ 子目录 {$parentId} 包含 " . count($channels) . '个通道/目录');

                            if ($this->autoInviteAfterRecCateLog) {
                                foreach ($channels as $newCh) {
                                    if ($newCh->parental === 0 && $newCh->forwardState === 0) {
                                        if (!$newCh->streaming && !$newCh->inviting) {
                                            $this->logger?->debug("[GSS]   🚀 子目录自动发起INVITE推流: {$newCh->channelId}");
                                            $chId2 = $newCh->channelId;
                                            Coroutine::create(function () use ($deviceId, $chId2) {
                                                $this->sendInvite($deviceId, $chId2);
                                            });
                                        }
                                    }
                                }
                            }
                        } else {
                            $this->logger?->debug("[GSS]   ⚠️ 未找到父目录 {$parentId}");
                        }
                    }
                    $this->lock->unlock();
                } else {
                    $this->lock->lock();
                    if (isset($this->devices[$deviceId])) {
                        $preserveStates = function (array $newChannels) use (&$preserveStates, $oldChannelMap) : void {
                            foreach ($newChannels as $newCh) {
                                if (isset($oldChannelMap[$newCh->channelId])) {
                                    $oldCh = $oldChannelMap[$newCh->channelId];
                                    $newCh->streaming = $oldCh->streaming;
                                    $newCh->rtpPort = $oldCh->rtpPort;
                                    $newCh->allocatedRtpPort = $oldCh->allocatedRtpPort;
                                    $newCh->callId = $oldCh->callId;
                                    $newCh->fromTag = $oldCh->fromTag;
                                    $newCh->toTag = $oldCh->toTag;
                                    $newCh->inviting = false;
                                    $newCh->forwardState = $oldCh->forwardState;
                                    $newCh->lastKeepaliveTime = $oldCh->lastKeepaliveTime;
                                    $newCh->lastRegisterTime = $oldCh->lastRegisterTime;
                                    $newCh->sn = $oldCh->sn;
                                }
                                if ($newCh->children) $preserveStates($newCh->children);
                            }
                        };
                        $preserveStates($channels);
                        $this->devices[$deviceId]->channels = $channels;
                        $this->logger?->debug("[GSS] ✓ 设备 {$deviceId} 通道列表已更新: " . count($channels) . '个通道/目录');

                        if ($this->autoInviteAfterRecCateLog) {
                            foreach ($channels as $newCh) {
                                if ($newCh->parental === 0 && $newCh->forwardState === 0) {
                                    if (!$newCh->streaming && !$newCh->inviting) {
                                        $this->logger?->debug("[GSS]   🚀 自动发起INVITE推流: {$newCh->channelId}");
                                        $chId3 = $newCh->channelId;
                                        Coroutine::create(function () use ($deviceId, $chId3) {
                                            $this->sendInvite($deviceId, $chId3);
                                        });
                                    }
                                }
                            }
                        }
                    }
                    $this->lock->unlock();
                }

                return $channels;
            } finally {
                $catalogLock->unlock();
            }
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] 处理Catalog响应异常: ' . $e->getMessage());
        }
        return [];
    }

    /** @param Channel[] $channels @return Channel|null */
    private function findChannel(array $channels, string $channelId) : ?Channel
    {
        foreach ($channels as $ch) {
            if ($ch->channelId === $channelId) return $ch;
            if ($ch->children) {
                $found = $this->findChannel($ch->children, $channelId);
                if ($found) return $found;
            }
        }
        return null;
    }

    public function querySubCatalog(string $deviceId, string $catalogId) : void
    {
        Coroutine::sleep(1); // 稍作延迟，等待父目录添加到列表

        $this->lock->lock();
        if (!isset($this->devices[$deviceId])) {
            $this->logger?->debug("[GSS] ⚠️ 设备 {$deviceId} 已不存在，取消子目录查询");
            $this->lock->unlock();
            return;
        }
        $device = $this->devices[$deviceId];
        $deviceIp = $device->ip;
        $devicePort = $device->port;
        $this->lock->unlock();

        $callId = str_replace('-', '', gb_uuid4());
        $fromTag = (string)random_int(100000000, 999999999);
        $branch = 'z9hG4bK' . random_int(100000000, 999999999);
        $sn = random_int(1, 9999);

        $body = "<?xml version=\"1.0\"?>\r\n<Query>\r\n<CmdType>Catalog</CmdType>\r\n<SN>{$sn}</SN>\r\n<DeviceID>{$catalogId}</DeviceID>\r\n</Query>\r\n";

        $msg = "MESSAGE sip:{$catalogId}@{$deviceIp}:{$devicePort} SIP/2.0\r\n"
            . "Via: SIP/2.0/{$this->getSipTransport()} {$this->serverIp}:{$this->serverPort};rport;branch={$branch}\r\n"
            . "From: <sip:{$this->serverId}@{$this->realm}>;tag={$fromTag}\r\n"
            . "To: <sip:{$catalogId}@{$this->realm}>\r\n"
            . "Call-ID: {$callId}\r\n"
            . "CSeq: 1 MESSAGE\r\n"
            . "Max-Forwards: 70\r\n"
            . "Contact: <sip:{$this->serverId}@{$this->serverIp}:{$this->serverPort}>\r\n"
            . "Content-Type: Application/MANSCDP+xml\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "\r\n"
            . $body;

        try {
            $addr = [$deviceIp, $devicePort];
            $this->sendSipResponse($msg, $addr);
            $this->logger?->debug("[GSS] 📂 已发送子目录查询: {$catalogId}");
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] 发送子目录查询失败: ' . $e->getMessage());
        }
    }

    /** @return array{0:?Device,1:?Channel} */
    private function findChannelById(string $channelId) : array
    {
        $this->lock->lock();
        try {
            foreach ($this->devices as $device) {
                $channel = $this->findChannel($device->channels, $channelId);
                if ($channel) return [$device, $channel];
            }
        } finally {
            $this->lock->unlock();
        }
        return [null, null];
    }

    private function openRtpServer(int $port, string $channelId) : int
    {
        try {
            $tcpMode = $this->rtpTransferMode === 1 ? 2 : 0;
            $this->logger?->debug("[GSS] 🔧 创建RTP服务器: 请求端口={$port}, tcp_mode={$tcpMode}, stream_id={$channelId}");
            [$success, $msg, $actualPort] = $this->zlm->openRtpServer($port, $tcpMode, $channelId);
            if ($success) {
                $this->logger?->debug("[GSS] ✅ RTP服务器创建成功: 实际端口={$actualPort}, stream_id={$channelId}");
                return $actualPort;
            }
            $this->logger?->error("[GSS] ❌ ZLM openRtpServer失败: {$msg}");
            return 0;
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] ❌ ZLM openRtpServer异常: ' . $e->getMessage());
            return 0;
        }
    }

    private function closeRtpServer(string $channelId) : bool
    {
        try {
            [$hit, $msg] = $this->zlm->closeRtpServer($channelId);
            if ($hit != 1) {
                $this->logger?->debug("[GSS] ⚠️ ZLM closeRtpServer: {$msg}");
            }
            return $hit == 1;
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] ❌ ZLM closeRtpServer异常: ' . $e->getMessage());
            return false;
        }
    }

    // -------------------------------------------------------------
    // requestInvite / requestBye / requestPtz（对外API，供业务层调用）
    // -------------------------------------------------------------

    /** @return array{0:bool,1:string} */
    public function requestInvite(string $clientId, string $channelId, bool $force = false) : array
    {
        $this->logger?->debug("[GSS] 🔍 开始INVITE请求: client_id={$clientId}, channel_id={$channelId}, force=" . ($force ? 'true' : 'false'));
        try {
            $this->lock->lock();
            $device = $this->devices[$clientId] ?? null;
            if (!$device) {
                $this->logger?->debug("[GSS] ❌ 设备未注册: {$clientId}");
                $this->lock->unlock();
                return [false, 'client not registered'];
            }

            $channel = $this->findChannel($device->channels, $channelId);
            if (!$channel) {
                $this->logger?->debug("[GSS] ⚠️ 通道 {$channelId} 不在内存中，创建临时通道");
                $channel = new Channel($channelId, $channelId, $device, $this->logger);
                $channel->parental = 0;
                $channel->forwardState = 0;
                $device->channels[] = $channel;
                Coroutine::create(function () use ($clientId) {
                    $this->queryCatalog($clientId);
                });
            }

            $oldCallId = $channel->callId;
            $oldFromTag = $channel->fromTag;
            $oldToTag = $channel->toTag;
            $oldAllocatedRtpPort = $channel->allocatedRtpPort;
            $oldDeviceIp = $device->ip;
            $oldDevicePort = $device->port;

            if ($channel->streaming || $channel->forwardState === 1) {
                if (!$force) {
                    $this->logger?->debug("[GSS] ⚠️ 通道已在推流中: {$channelId}，直接返回成功");
                    $this->lock->unlock();
                    return [true, 'already streaming'];
                }
                $this->logger?->debug("[GSS] 🔄 通道 {$channelId} 标记为推流中但force=True，先发BYE终止旧会话");
            }

            $needSendBye = false;
            if ($oldCallId !== '') {
                $needSendBye = true;
                $channel->streaming = false;
                $channel->forwardState = 0;
                $channel->callId = '';
                $channel->fromTag = '';
                $channel->toTag = '';
                $channel->rtpPort = 0;
                $channel->allocatedRtpPort = 0;
            }

            if ($channel->inviting) {
                $this->logger?->debug("[GSS] ⚠️ 通道 {$channelId} inviting标志为True，可能是残留状态，强制清除");
                $channel->inviting = false;
            }
            $channel->inviting = true;

            $deviceIp = $device->ip;
            $devicePort = $device->port;
            $this->lock->unlock();

            if ($needSendBye) {
                $this->sendByeForChannel($oldDeviceIp, (int)$oldDevicePort, $channelId, $oldCallId, $oldFromTag, $oldToTag);
                $this->closeRtpServer($channelId);
                if ($oldAllocatedRtpPort > 0) {
                    $this->rtpPortMgr->release($oldAllocatedRtpPort);
                }
                Coroutine::sleep(0.5); // 等待BYE被设备处理
            }

            $this->logger?->debug("[GSS] 找到设备和通道: {$device->deviceId}, {$channel->channelId}");

            $rtpPort = $this->rtpPortMgr->allocate($channelId);
            if ($rtpPort === 0) {
                $this->logger?->error('[GSS] ❌ 无法分配RTP端口');
                $this->lock->lock();
                $device = $this->devices[$clientId] ?? null;
                if ($device) {
                    $channel = $this->findChannel($device->channels, $channelId);
                    if ($channel) $channel->inviting = false;
                }
                $this->lock->unlock();
                return [false, 'no available RTP port'];
            }

            $this->logger?->debug("[GSS] 🔧 request_invite分配RTP端口: {$rtpPort}, channel_id={$channelId}");

            $zlmRtpPort = 0;
            try {
                $tcpMode = $this->rtpTransferMode === 1 ? 2 : 0;
                $this->logger?->debug("[GSS] 🔧 创建RTP服务器: 请求端口={$rtpPort}, tcp_mode={$tcpMode}, stream_id={$channelId}");
                [$success, $msg, $zlmRtpPort] = $this->zlm->openRtpServer($rtpPort, $tcpMode, $channelId);
                if (!$success) {
                    if (str_contains((string)$msg, 'already exists')) {
                        $this->logger?->debug("[GSS] ⚠️ RTP服务器已存在，使用现有服务器: {$channelId}");
                        $zlmRtpPort = $rtpPort;
                    } else {
                        $this->logger?->error("[GSS] ❌ ZLM openRtpServer失败: {$msg}");
                        $this->rtpPortMgr->release($rtpPort);
                        $this->lock->lock();
                        $device = $this->devices[$clientId] ?? null;
                        if ($device) {
                            $channel = $this->findChannel($device->channels, $channelId);
                            if ($channel) $channel->inviting = false;
                        }
                        $this->lock->unlock();
                        return [false, "failed to open RTP server on ZLM: {$msg}"];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger?->error('[GSS] ❌ ZLM openRtpServer异常: ' . $e->getMessage());
                $this->rtpPortMgr->release($rtpPort);
                $this->lock->lock();
                $device = $this->devices[$clientId] ?? null;
                if ($device) {
                    $channel = $this->findChannel($device->channels, $channelId);
                    if ($channel) $channel->inviting = false;
                }
                $this->lock->unlock();
                return [false, 'failed to open RTP server on ZLM: ' . $e->getMessage()];
            }

            $this->logger?->debug("[GSS] ✅ RTP服务器创建成功: 请求端口={$rtpPort}, ZLM实际端口={$zlmRtpPort}, stream_id={$channelId}");

            $includeAudio = ($this->rtpTransferAudioType === 1);
            $sdpBody = $this->buildSdp('video', $zlmRtpPort, [96, 98, 97],
                [96 => 'PS/90000', 98 => 'H264/90000', 97 => 'MPEG4/90000'], $includeAudio);

            $callId = str_replace('-', '', gb_uuid4());
            $fromTag = (string)random_int(100000000, 999999999);
            $branch = 'z9hG4bK' . random_int(100000000, 999999999);

            $inviteMsg = "INVITE sip:{$channelId}@{$deviceIp}:{$devicePort} SIP/2.0\r\n"
                . "Via: SIP/2.0/{$this->getSipTransport()} {$this->serverIp}:{$this->serverPort};rport;branch={$branch}\r\n"
                . "From: <sip:{$this->serverId}@{$this->realm}>;tag={$fromTag}\r\n"
                . "To: <sip:{$channelId}@{$this->realm}>\r\n"
                . "Call-ID: {$callId}\r\n"
                . "CSeq: 1 INVITE\r\n"
                . "Max-Forwards: 70\r\n"
                . "Contact: <sip:{$this->serverId}@{$this->serverIp}:{$this->serverPort}>\r\n"
                . "Content-Type: application/sdp\r\n"
                . 'Content-Length: ' . strlen($sdpBody) . "\r\n"
                . "\r\n"
                . $sdpBody;

            $addr = [$deviceIp, $devicePort];
            $this->sendSipResponse($inviteMsg, $addr);

            $this->pendingInvitesLock->lock();
            $this->pendingInvites[$channelId] = [
                'rtp_port'     => $rtpPort,
                'call_id'      => $callId,
                'device_id'    => $clientId,
                'old_call_id'  => $oldCallId ? : '',
                'old_from_tag' => $oldFromTag ? : '',
                'old_to_tag'   => $oldToTag ? : '',
            ];
            $this->pendingInvitesLock->unlock();

            $this->lock->lock();
            $device = $this->devices[$clientId] ?? null;
            if ($device) {
                $channel = $this->findChannel($device->channels, $channelId);
                if ($channel) {
                    $channel->rtpPort = $zlmRtpPort;
                    $channel->allocatedRtpPort = $rtpPort;
                    $channel->callId = $callId;
                    $channel->fromTag = $fromTag;
                    $channel->inviting = false;
                    $this->pendingInvitesLock->lock();
                    unset($this->pendingInvites[$channelId]);
                    $this->pendingInvitesLock->unlock();
                    $this->stats['total_invites']++;
                } else {
                    $this->logger?->debug("[GSS] ⚠️ 通道 {$channelId} 在INVITE成功后已不存在，清理资源");
                    $this->rtpPortMgr->release($rtpPort);
                    $this->closeRtpServer($channelId);
                    $this->lock->unlock();
                    return [false, 'channel not found after INVITE sent'];
                }
            } else {
                $this->logger?->debug("[GSS] ⚠️ 设备 {$clientId} 在INVITE成功后已不存在，清理资源");
                $this->rtpPortMgr->release($rtpPort);
                $this->closeRtpServer($channelId);
                $this->lock->unlock();
                return [false, 'device not found after INVITE sent'];
            }
            $this->lock->unlock();

            return [true, 'success'];
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] ❌ INVITE请求异常: ' . $e->getMessage());
            $this->logger?->error('[GSS] 异常堆栈: ' . $e->getTraceAsString());
            try {
                if (isset($rtpPort) && $rtpPort > 0) {
                    $this->rtpPortMgr->release($rtpPort);
                }
                $this->closeRtpServer($channelId);
                $this->lock->lock();
                $device = $this->devices[$clientId] ?? null;
                if ($device) {
                    $channel = $this->findChannel($device->channels, $channelId);
                    if ($channel) {
                        $channel->inviting = false;
                        $channel->rtpPort = 0;
                        $channel->allocatedRtpPort = 0;
                        $channel->callId = '';
                        $channel->streaming = false;
                        $channel->forwardState = 0;
                    }
                }
                $this->lock->unlock();
            } catch (\Throwable $cleanupE) {
                $this->logger?->error('[GSS] ❌ 清理INVITE资源异常: ' . $cleanupE->getMessage());
            }
            return [false, 'invite error: ' . $e->getMessage()];
        }
    }

    /** @return array{0:bool,1:string} */
    public function requestBye(string $clientId, string $channelId) : array
    {
        try {
            $this->lock->lock();
            $device = $this->devices[$clientId] ?? null;
            if (!$device) {
                $this->lock->unlock();
                return [false, 'client not registered'];
            }

            $channel = $this->findChannel($device->channels, $channelId);
            if (!$channel) {
                $this->logger?->debug("[GSS] ⚠️ 通道 {$channelId} 不在内存中，尝试清理资源");
                $this->lock->unlock();
                $this->closeRtpServer($channelId);
                $this->pendingInvitesLock->lock();
                $pending = $this->pendingInvites[$channelId] ?? null;
                unset($this->pendingInvites[$channelId]);
                $this->pendingInvitesLock->unlock();
                if ($pending) $this->rtpPortMgr->release((int)$pending['rtp_port']);
                return [true, 'resource cleaned'];
            }

            if (!$channel->streaming) {
                $this->logger?->debug("[GSS] ⚠️ 通道 {$channelId} 未在推流，但仍需清理资源");
                $this->lock->unlock();
                $this->closeRtpServer($channelId);
                $this->pendingInvitesLock->lock();
                $pending = $this->pendingInvites[$channelId] ?? null;
                unset($this->pendingInvites[$channelId]);
                $this->pendingInvitesLock->unlock();
                if ($pending) $this->rtpPortMgr->release((int)$pending['rtp_port']);

                $channel->rtpPort = 0;
                $channel->allocatedRtpPort = 0;
                $channel->callId = '';
                $channel->forwardState = 0;
                $channel->inviting = false;
                return [true, 'resource cleaned'];
            }

            $callId = $channel->callId;
            $fromTag = $channel->fromTag;
            $toTag = $channel->toTag;
            $deviceIp = $device->ip;
            $devicePort = $device->port;
            $allocatedRtpPort = $channel->allocatedRtpPort;

            $channel->rtpPort = 0;
            $channel->allocatedRtpPort = 0;
            $channel->callId = '';
            $channel->fromTag = '';
            $channel->toTag = '';
            $channel->streaming = false;
            $channel->forwardState = 0;
            $channel->inviting = false;
            $this->lock->unlock();

            $this->sendByeForChannel($deviceIp, (int)$devicePort, $channelId, $callId, $fromTag, $toTag);
            $this->closeRtpServer($channelId);
            if ($allocatedRtpPort > 0) {
                $this->rtpPortMgr->release($allocatedRtpPort);
            }

            $this->lock->lock();
            $this->stats['total_byes']++;
            $this->lock->unlock();

            return [true, 'success'];
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] BYE请求异常: ' . $e->getMessage());
            try {
                $this->closeRtpServer($channelId);
                if (isset($allocatedRtpPort) && $allocatedRtpPort > 0) {
                    $this->rtpPortMgr->release($allocatedRtpPort);
                }
            } catch (\Throwable $cleanupE) {
                $this->logger?->error('[GSS] ❌ 清理BYE资源异常: ' . $cleanupE->getMessage());
            }
            return [false, 'bye error: ' . $e->getMessage()];
        }
    }

    /** @return array{0:bool,1:string} */
    public function requestPtz(string $clientId, string $channelId, int $ptzType, int $val) : array
    {
        try {
            $this->lock->lock();
            $device = $this->devices[$clientId] ?? null;
            if (!$device) {
                $this->lock->unlock();
                return [false, 'client not registered'];
            }

            $channel = $this->findChannel($device->channels, $channelId);
            if (!$channel) {
                $this->lock->unlock();
                return [false, 'channel not found'];
            }

            $channel->sn++;
            $sn = $channel->sn;
            $deviceIp = $device->ip;
            $devicePort = $device->port;
            $this->lock->unlock();

            $ptzCmd = $this->buildPtzCommand($ptzType, $val);

            $body = "<?xml version=\"1.0\"?>\r\n<ControlInfo>\r\n<CmdType>DeviceControl</CmdType>\r\n<SN>{$sn}</SN>\r\n<DeviceID>{$channelId}</DeviceID>\r\n<PTZCmd>{$ptzCmd}</PTZCmd>\r\n</ControlInfo>\r\n";

            $callId = str_replace('-', '', gb_uuid4());
            $fromTag = (string)random_int(100000000, 999999999);
            $branch = 'z9hG4bK' . random_int(100000000, 999999999);

            $msg = "MESSAGE sip:{$channelId}@{$deviceIp}:{$devicePort} SIP/2.0\r\n"
                . "Via: SIP/2.0/{$this->getSipTransport()} {$this->serverIp}:{$this->serverPort};rport;branch={$branch}\r\n"
                . "From: <sip:{$this->serverId}@{$this->realm}>;tag={$fromTag}\r\n"
                . "To: <sip:{$channelId}@{$this->realm}>\r\n"
                . "Call-ID: {$callId}\r\n"
                . "CSeq: 1 MESSAGE\r\n"
                . "Max-Forwards: 70\r\n"
                . "Contact: <sip:{$this->serverId}@{$this->serverIp}:{$this->serverPort}>\r\n"
                . "Content-Type: Application/MANSCDP+xml\r\n"
                . 'Content-Length: ' . strlen($body) . "\r\n"
                . "\r\n"
                . $body;

            $addr = [$deviceIp, $devicePort];
            $this->sendSipResponse($msg, $addr);

            $ptzNames = [0 => '停止', 1 => '右转', 3 => '上转', 5 => '左转', 7 => '下转', 9 => '变焦', 10 => '光圈', 11 => '聚焦'];
            $ptzName = $ptzNames[$ptzType] ?? "类型{$ptzType}";
            $this->logger?->debug("[GSS] 🎮 已发送PTZ指令: {$channelId} - {$ptzName} (val={$val})");

            return [true, 'success'];
        } catch (\Throwable $e) {
            $this->logger?->error('[GSS] PTZ请求异常: ' . $e->getMessage());
            return [false, 'ptz error: ' . $e->getMessage()];
        }
    }

    private function buildPtzCommand(int $ptzType, int $val) : string
    {
        $speed = min(max($val, 0), 255);

        $cmdMap = [
            0  => 0x00, 1 => 0x02, 2 => 0x0A, 3 => 0x08, 4 => 0x09,
            5  => 0x04, 6 => 0x0C, 7 => 0x10, 8 => 0x06, 9 => 0x20,
            10 => 0x40, 11 => 0x10,
        ];
        $cmdByte = $cmdMap[$ptzType] ?? 0x00;

        $ptzBytes = [0xA5, 0x0F, 0x01, $cmdByte, $speed, $speed, 0x00];
        $checksum = array_sum($ptzBytes) & 0xFF;
        $ptzBytes[] = $checksum;

        $hex = '';
        foreach ($ptzBytes as $b) {
            $hex .= strtoupper(str_pad(dechex($b), 2, '0', STR_PAD_LEFT));
        }
        return $hex;
    }
}

// =====================================================================
// 工具函数：UUID v4（对应  的 uuid.uuid4()）
// =====================================================================
function gb_uuid4() : string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// =====================================================================
// 简易 Logger 实现（与  logging 接口保持一致：debug/info/error）
// 生产环境可替换为 Monolog 等实现，只需保持相同的方法签名。
// =====================================================================
final class SimpleLogger
{
    public function debug(string $msg) : void
    {
        $this->write('DEBUG', $msg);
    }

    public function info(string $msg) : void
    {
        $this->write('INFO', $msg);
    }

    public function error(string $msg) : void
    {
        $this->write('ERROR', $msg);
    }

    private function write(string $level, string $msg) : void
    {
        $ts = date('Y-m-d H:i:s');
        fwrite(STDOUT, "[{$ts}][{$level}] {$msg}\n");
    }
}

/**
 * ZLMediaKitApi 接口约定（需由使用方提供真实实现，通常基于 Swoole\Coroutine\Http\Client
 * 调用 ZLMediaKit 的 /index/api/openRtpServer 与 /index/api/closeRtpServer 接口）。
 *
 * interface ZLMediaKitApiInterface {
 *     public function openRtpServer(int $port, int $tcpMode, string $streamId): array; // [bool $success, string $msg, int $port]
 *     public function closeRtpServer(string $streamId): array;                          // [int $hit, string $msg]
 * }
 */

// =====================================================================
// 启动示例（对应  版本 main 部分的典型用法）
// =====================================================================
if (php_sapi_name() === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    \Swoole\Coroutine\run(function () {
        $logger = new SimpleLogger();

        // 请替换为真实的 ZLMediaKitApi 实现
        $zlm = new class {
            public function openRtpServer(int $port, int $tcpMode, string $streamId) : array
            {
                // TODO: 调用真实 ZLMediaKit /index/api/openRtpServer
                return [true, 'ok', $port];
            }

            public function closeRtpServer(string $streamId) : array
            {
                // TODO: 调用真实 ZLMediaKit /index/api/closeRtpServer
                return [1, 'ok'];
            }
        };

        $server = new GBServer(
            serverIp: '0.0.0.0',
            serverPort: 15060,
            serverId: '34020000002000000001',
            realm: '3402000000',
            password: '12345678',
            sipServerTimeout: 120,
            sipServerExpiry: 60,
            sipTransferMode: 0,
            rtpTransferMode: 0,
            rtpTransferAudioType: 0,
            autoInviteAfterRecCateLog: true,
            adminHost: null,
            zlm: $zlm,
            logger: $logger
        );

        $server->start();

        // 保持进程存活
        while ($server->running) {
            Coroutine::sleep(1);
        }
    });
}