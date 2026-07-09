<?php

namespace Gb28181Gateway\src\SwooleServer;

use Swoole\Coroutine;

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
     * 同步通道信息到 rebekah_admin 数据库（与 C++ / Python 版本逻辑一致）
     * @return array{0: bool, 1: string}
     */
    public function updateAdmin(GBS $server) : array
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
                [$ok, $status, $body, $err] = Helper::gb_http_post_json($url, $params, 5.0);

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