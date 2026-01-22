<?php

namespace Gb28181\GateWay\Device;

use Gb28181\GateWay\Transport\SipClient;
use Exception;

/**
 * 表示一个连接到服务器的 GB28181 设备
 *
 * 扩展字段说明：
 * - 传输模式：rtpTransMode
 * - 订阅配置：subscribeCatalog, subscribeAlarm, subscribePosition, subscribePtz
 * - 字符集/码流：charset, streamIndex
 * - 通道过滤：filterChannelTypes
 *
 * 注意：收流IP由通道绑定的流媒体服务器决定，不在设备级别配置
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

    // ==================== 扩展配置字段 ====================

    // 流传输模式: 0=UDP, 1=TCP被动(设备连平台), 2=TCP主动(平台连设备)
    public int $rtpTransMode = 0;

    // 订阅配置
    public bool $subscribeCatalog = false;   // 订阅目录变更
    public bool $subscribeAlarm = false;     // 订阅报警事件
    public bool $subscribePosition = false;  // 订阅位置上报
    public bool $subscribePtz = false;       // 订阅PTZ控制反馈(2022)
    public int $subscribeExpires = 3600;     // 订阅有效期（秒）
    public int $positionInterval = 60;       // 位置上报间隔（秒）

    // 通道更新配置
    public int $catalogInterval = 3600;      // 目录更新周期（秒），0=禁用轮询
    public int $lastCatalogAt = 0;           // 上次目录查询时间

    // 字符集和码流
    public string $charset = 'auto';       // 设备XML字符集: gb2312/utf8/auto

    public string $streamIndex = 'auto';     // 码流索引: auto/0/1

    // 通道过滤
    public array $filterChannelTypes = [];   // 过滤的通道类型列表，如[134, 135]

    // 录像配置
    public string $recordMode = 'center';    // 设备录像模式: center=中心检索, fuzzy=模糊查询
    public string $catalogStructure = 'area'; // 目录结构: area=行政区域优先, device=设备优先

    // 订阅状态追踪
    public array $subscriptions = [];        // 活跃订阅信息 [type => subscription_data]

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
        $this->registeredAt = $data['registered_at'] ?? null;
        $this->expires = $data['expires'] ?? 3600;

        if (isset($data['info'])) {
            $this->info = $data['info'];
        }

        // 加载扩展配置
        $this->loadExtendedConfig($data);
    }

    /**
     * 加载扩展配置字段
     */
    private function loadExtendedConfig(array $data): void
    {
        // 传输模式
        $this->rtpTransMode = (int)($data['rtp_trans_mode'] ?? 0);

        // 订阅配置
        $this->subscribeCatalog = (bool)($data['subscribe_catalog'] ?? false);
        $this->subscribeAlarm = (bool)($data['subscribe_alarm'] ?? false);
        $this->subscribePosition = (bool)($data['subscribe_position'] ?? false);
        $this->subscribePtz = (bool)($data['subscribe_ptz'] ?? false);
        $this->subscribeExpires = (int)($data['subscribe_expires'] ?? 3600);
        $this->positionInterval = (int)($data['position_interval'] ?? 60);

        // 目录更新配置
        $this->catalogInterval = (int)($data['catalog_interval'] ?? 3600);
        $this->lastCatalogAt = (int)($data['last_catalog_at'] ?? 0);

        // 字符集和码流
        $this->charset = $data['charset'] ?? 'auto';
        $this->streamIndex = $data['stream_index'] ?? 'auto';

        // 通道过滤（支持 JSON 字符串或数组）
        $filterTypes = $data['filter_channel_types'] ?? [];
        if (is_string($filterTypes) && !empty($filterTypes)) {
            $filterTypes = json_decode($filterTypes, true) ?? [];
        }
        $this->filterChannelTypes = is_array($filterTypes) ? $filterTypes : [];

        // 录像配置
        $this->recordMode = $data['record_mode'] ?? 'center';
        $this->catalogStructure = $data['catalog_structure'] ?? 'area';

        // 订阅状态（支持 JSON 字符串或数组）
        $subscriptions = $data['subscription_status'] ?? [];
        if (is_string($subscriptions) && !empty($subscriptions)) {
            $subscriptions = json_decode($subscriptions, true) ?? [];
        }
        $this->subscriptions = is_array($subscriptions) ? $subscriptions : [];
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
            // 扩展配置字段
            'rtp_trans_mode' => $this->rtpTransMode,
            'subscribe_catalog' => $this->subscribeCatalog,
            'subscribe_alarm' => $this->subscribeAlarm,
            'subscribe_position' => $this->subscribePosition,
            'subscribe_ptz' => $this->subscribePtz,
            'subscribe_expires' => $this->subscribeExpires,
            'position_interval' => $this->positionInterval,
            'catalog_interval' => $this->catalogInterval,
            'last_catalog_at' => $this->lastCatalogAt,
            'charset' => $this->charset,
            'stream_index' => $this->streamIndex,
            'filter_channel_types' => $this->filterChannelTypes,
            'record_mode' => $this->recordMode,
            'catalog_structure' => $this->catalogStructure,
            'subscriptions' => $this->subscriptions,
        ];
    }

    /**
     * 获取需要订阅的事件类型列表
     *
     * @return array 事件类型列表，如 ['Catalog', 'Alarm', 'presence']
     */
    public function getSubscribeEvents(): array
    {
        $events = [];
        if ($this->subscribeCatalog) $events[] = 'Catalog';
        if ($this->subscribeAlarm) $events[] = 'Alarm';
        if ($this->subscribePosition) $events[] = 'presence';  // MobilePosition 使用 Event: presence
        if ($this->subscribePtz) $events[] = 'PTZControl';
        return $events;
    }

    /**
     * 检查是否需要刷新目录
     *
     * @return bool 是否需要查询目录
     */
    public function needsCatalogRefresh(): bool
    {
        if ($this->catalogInterval <= 0) {
            return false;  // 禁用轮询
        }

        return (time() - $this->lastCatalogAt) >= $this->catalogInterval;
    }

    /**
     * 更新目录查询时间
     */
    public function updateCatalogTime(): void
    {
        $this->lastCatalogAt = time();
    }

    /**
     * 更新扩展配置
     *
     * @param array $config 配置数据
     */
    public function updateConfig(array $config): void
    {
        // 传输模式
        if (isset($config['rtp_trans_mode'])) $this->rtpTransMode = (int)$config['rtp_trans_mode'];

        // 订阅配置
        if (isset($config['subscribe_catalog'])) $this->subscribeCatalog = (bool)$config['subscribe_catalog'];
        if (isset($config['subscribe_alarm'])) $this->subscribeAlarm = (bool)$config['subscribe_alarm'];
        if (isset($config['subscribe_position'])) $this->subscribePosition = (bool)$config['subscribe_position'];
        if (isset($config['subscribe_ptz'])) $this->subscribePtz = (bool)$config['subscribe_ptz'];
        if (isset($config['subscribe_expires'])) $this->subscribeExpires = (int)$config['subscribe_expires'];
        if (isset($config['position_interval'])) $this->positionInterval = (int)$config['position_interval'];

        // 目录更新配置
        if (isset($config['catalog_interval'])) $this->catalogInterval = (int)$config['catalog_interval'];

        // 字符集和码流
        if (isset($config['charset'])) $this->charset = $config['charset'];
        if (isset($config['stream_index'])) $this->streamIndex = $config['stream_index'];

        // 通道过滤
        if (isset($config['filter_channel_types'])) {
            $filterTypes = $config['filter_channel_types'];
            if (is_string($filterTypes)) {
                $filterTypes = json_decode($filterTypes, true) ?? [];
            }
            $this->filterChannelTypes = is_array($filterTypes) ? $filterTypes : [];
        }

        // 录像配置
        if (isset($config['record_mode'])) $this->recordMode = $config['record_mode'];
        if (isset($config['catalog_structure'])) $this->catalogStructure = $config['catalog_structure'];
    }

    /**
     * 判断设备是否在线
     */
    public function isOnline(): bool
    {
        return $this->registered && $this->status === 'online';
    }

    /**
     * 添加订阅
     * 
     * @param string $eventType 事件类型（Catalog, Alarm, MobilePosition）
     * @param int $subscriptionId ExoSip 返回的订阅 ID（用于后续取消订阅）
     * @param int $expires 订阅有效期（秒）
     * @param array $params 额外参数
     */
    public function addSubscription(string $eventType, int $subscriptionId, int $expires, array $params = []): void
    {
        $this->subscriptions[$eventType] = [
            'event_type' => $eventType,
            'subscription_id' => $subscriptionId,
            'expires' => $expires,
            'expires_at' => time() + $expires,
            'created_at' => time(),
            'params' => $params
        ];
    }

    /**
     * 移除订阅
     */
    public function removeSubscription(string $eventType): void
    {
        unset($this->subscriptions[$eventType]);
    }

    /**
     * 获取所有订阅
     */
    public function getSubscriptions(): array
    {
        return $this->subscriptions;
    }

    /**
     * 检查是否有某个订阅
     */
    public function hasSubscription(string $eventType): bool
    {
        return isset($this->subscriptions[$eventType]);
    }

    /**
     * 获取订阅ID（用于取消订阅）
     */
    public function getSubscriptionId(string $eventType): ?int
    {
        return $this->subscriptions[$eventType]['subscription_id'] ?? null;
    }

    /**
     * 清除过期的订阅
     */
    public function cleanExpiredSubscriptions(): void
    {
        $now = time();
        foreach ($this->subscriptions as $eventType => $subscription) {
            if (($subscription['expires_at'] ?? 0) < $now) {
                unset($this->subscriptions[$eventType]);
            }
        }
    }
}
