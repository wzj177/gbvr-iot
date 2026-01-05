<?php

namespace Gb28181\GateWay\Device;

use Gb28181\GateWay\Transport\SipClient;
use Exception;

/**
 * 表示一个连接到服务器的 GB28181 设备
 */
class Device
{
    // 设备标识
    public string $deviceId;
    public string $uri;
    public string $ip;
    public ?string $received_ip = null;
    public ?int $received_port = null;
    public int $port;

    // 注册状态
    public bool $registered = false;
    public int $registerTime = 0;
    public int $registeredAt = 0;
    public int $expires = 3600;

    // 心跳状态
    public int $lastHeartbeat = 0;
    public int $heartbeatCount = 0;
    public int $timeoutCount = 0;

    // 设备状态
    public string $status = 'created';

    // 设备信息
    public array $info = [];  // name, manufacturer, model, firmware, channels

    // 通道列表
    public array $channels = [];

    /**
     * 构造函数
     */
    public function __construct(string $deviceId, array $data = [])
    {
        $this->deviceId = $deviceId;
        $this->uri = $data['uri'] ?? '';
        $this->ip = $data['ip'] ?? '';
        $this->received_ip = $data['received_ip'] ?? null;
        $this->received_port = $data['received_port'] ?? null;
        $this->port = $data['port'] ?? 0;
        $this->registeredAt = $data['registered_at'] ?? 0;
        $this->expires = $data['expires'] ?? 3600;

        if (isset($data['info'])) {
            $this->info = $data['info'];
        }
    }

    /**
     * 标记设备已注册
     */
    public function markRegistered(): void
    {
        $this->registered = true;
        $this->registerTime = time();
        $this->lastHeartbeat = time();
        $this->status = 'online';
    }

    /**
     * 标记设备已注销
     */
    public function markUnregistered(): void
    {
        $this->registered = false;
        $this->status = 'unregistered';
    }

    /**
     * 记录心跳
     */
    public function recordHeartbeat(): void
    {
        $this->lastHeartbeat = time();
        $this->heartbeatCount++;
        $this->status = 'online';

        // 重置超时计数
        if ($this->timeoutCount > 0) {
            $this->timeoutCount = 0;
        }
    }

    /**
     * 标记超时
     */
    public function markTimeout(): void
    {
        $this->status = 'timeout';
        $this->timeoutCount++;
    }

    /**
     * 检查是否超时
     */
    public function isTimeout(int $timeoutSeconds): bool
    {
        if (!$this->registered || $this->status !== 'online') {
            return false;
        }

        return (time() - $this->lastHeartbeat) > $timeoutSeconds;
    }

    /**
     * 更新设备信息
     */
    public function updateInfo(array $info): void
    {
        $this->info = array_merge($this->info, $info);
    }

    /**
     * 设置通道列表
     */
    public function setChannels(array $channels): void
    {
        $this->channels = $channels;
    }

    /**
     * 获取设备信息数组
     */
    public function toArray(): array
    {
        return [
            'device_id' => $this->deviceId,
            'uri' => $this->uri,
            'ip' => $this->ip,
            'received_ip' => $this->received_ip,
            'received_port' => $this->received_port,
            'port' => $this->port,
            'registered' => $this->registered,
            'register_time' => $this->registerTime,
            'registered_at' => $this->registeredAt,
            'expires' => $this->expires,
            'last_heartbeat' => $this->lastHeartbeat,
            'heartbeat_count' => $this->heartbeatCount,
            'timeout_count' => $this->timeoutCount,
            'status' => $this->status,
            'info' => $this->info,
            'channels' => $this->channels,
        ];
    }

    /**
     * 判断设备是否在线
     */
    public function isOnline(): bool
    {
        return $this->registered && $this->status === 'online';
    }
}
