<?php

namespace Gb28181\GateWay\Device;

use \Exception;
use Gb28181\GateWay\Libs\Logger;

/**
 * 设备管理器 - 管理 GB28181 服务器端设备
 *
 * 功能：
 * - 设备注册/注销监控
 * - 心跳超时检测
 * - 设备状态统计
 * - 设备信息管理
 */
class DeviceManager
{
    // 设备对象列表 (服务器端 - 管理连接进来的设备)
    // [deviceId => Device]
    private array $devices = [];

    private int $heartbeatTimeout = 180;  // 3分钟心跳超时（GB28181建议值）
    private int $checkInterval = 30;      // 30秒检查一次
    private int $lastCheckTime = 0;
    private Logger $logger;

    // 持久化配置
    private string $cacheFile = '/tmp/gb28181_devices.cache';
    /** @var callable|null */
    private $apiLoader = null;
    private int $lastCacheSave = 0;
    private int $cacheSaveInterval = 60;  // 每60秒保存一次缓存

    /**
     * 构造函数
     *
     * @param int $heartbeatTimeout 心跳超时时间（秒）
     * @param int $checkInterval 检查间隔（秒）
     * @param array $options 可选配置 ['cache_file', 'api_loader']
     */
    public function __construct(int $heartbeatTimeout = 180, int $checkInterval = 30, array $options = [])
    {
        $this->heartbeatTimeout = $heartbeatTimeout;
        $this->checkInterval = $checkInterval;
        $this->lastCheckTime = time();
        $this->logger = Logger::getInstance();

        // 持久化配置
        if (isset($options['cache_file'])) {
            $this->cacheFile = $options['cache_file'];
        }

        if (isset($options['api_loader']) && is_callable($options['api_loader'])) {
            $this->apiLoader = $options['api_loader'];
        }

        $this->log("DeviceManager 已初始化");

        // 启动时加载设备列表
        $this->loadDevices();
    }

    /**
     * 添加新设备（从 REGISTER 事件创建）
     *
     * @param string $deviceId 设备ID
     * @param array $info 设备初始信息 ['uri', 'ip', 'port', 'registered_at', 'expires']
     * @return Device
     */
    public function addDevice(string $deviceId, array $info) : Device
    {
        if (isset($this->devices[$deviceId])) {
            throw new \Exception("设备已存在: {$deviceId}");
        }

        $device = new Device($deviceId, $info);
        $device->markRegistered();
        $this->devices[$deviceId] = $device;

        $this->log("设备已添加: {$deviceId}");

        return $device;
    }

    /**
     * 移除设备
     */
    public function removeDevice(string $deviceId) : void
    {
        unset($this->devices[$deviceId]);
        $this->log("设备已移除: {$deviceId}");
    }

    /**
     * 更新设备信息（只更新已存在的设备）
     *
     * @param string $deviceId 设备ID
     * @param array $info 要更新的信息 ['uri', 'ip', 'port', 'registered_at', 'expires', 'info']
     * @return bool 是否更新成功
     */
    public function updateDeviceInfo(string $deviceId, array $info) : bool
    {
        /** @var Device $device */
        $device = $this->devices[$deviceId] ?? null;
        if (!$device) {
            $this->log("设备不存在，无法更新: {$deviceId}", 'WARNING');
            return false;
        }

        // 更新设备属性
        if (isset($info['uri'])) $device->uri = $info['uri'];
        if (isset($info['ip'])) $device->ip = $info['ip'];
        if (isset($info['received_ip'])) $device->received_ip = $info['received_ip'];
        if (isset($info['received_port'])) $device->received_port = $info['received_port'];
        if (isset($info['port'])) $device->port = $info['port'];
        if (isset($info['registered_at'])) $device->registeredAt = is_string($info['registered_at']) ? strtotime($info['registered_at']) : $info['registered_at'];
        if (isset($info['expires'])) $device->expires = $info['expires'];
        if (isset($info['info'])) $device->updateInfo($info['info']);

        // 更新扩展配置（如有）
        $device->updateConfig($info);

        return true;
    }

    /**
     * 更新设备配置（API 通知更新时调用）
     *
     * @param string $deviceId 设备ID
     * @param array $config 配置数据
     * @return bool 是否更新成功
     */
    public function updateDeviceConfig(string $deviceId, array $config) : bool
    {
        $device = $this->devices[$deviceId] ?? null;
        if (!$device) {
            $this->log("设备不存在，无法更新配置: {$deviceId}", 'WARNING');
            return false;
        }

        $device->updateConfig($config);
        $this->log("设备配置已更新: {$deviceId}");

        return true;
    }

    /**
     * 获取设备信息（服务器端使用）
     * @return array|null 返回设备信息数组，兼容旧代码
     */
    public function getDevice(string $deviceId) : ?array
    {
        $device = $this->devices[$deviceId] ?? null;
        return $device ? $device->toArray() : null;
    }

    /**
     * 获取设备对象
     * @return Device|null
     */
    public function getDeviceObject(string $deviceId) : ?Device
    {
        return $this->devices[$deviceId] ?? null;
    }

    /**
     * 获取设备字符集
     * @return string 设备字符集，默认 'GB2312'
     */
    public function getDeviceCharset(string $deviceId) : string
    {
        $device = $this->getDevice($deviceId);
        return $device['charset'] ?? 'GB2312';
    }

    /**
     * 更新设备心跳（由 Device 对象内部调用）
     * 保留此方法用于外部监控
     */
    public function recordHeartbeat(string $deviceId) : void
    {
        $device = $this->devices[$deviceId] ?? null;
        if ($device) {
            $wasTimeout = $device->status === 'timeout';
            $device->recordHeartbeat();

            if ($wasTimeout) {
                $this->log("设备恢复在线: {$deviceId}");
            }
        }
    }

    /**
     * 更新设备心跳（兼容旧接口）
     *
     * @param string $deviceId 设备ID
     */
    public function updateHeartbeat(string $deviceId) : void
    {
        $this->recordHeartbeat($deviceId);
    }

    /**
     * 检查超时设备（定期调用）
     *
     * @return array 超时的设备列表
     */
    public function checkTimeout() : array
    {
        $now = time();

        // 控制检查频率
        if ($now - $this->lastCheckTime < $this->checkInterval) {
            return [];
        }

        $this->lastCheckTime = $now;
        $timeoutDevices = [];

        foreach ($this->devices as $deviceId => $device) {
            /** @var Device $device */
            if ($device->isTimeout($this->heartbeatTimeout)) {
                $device->markTimeout();
                $timeSinceHeartbeat = $now - $device->lastHeartbeat;

//                $this->log("设备心跳超时: {$deviceId}, 距离上次心跳: {$timeSinceHeartbeat}秒", 'ERROR');
                $timeoutDevices[] = $device->toArray();
            } // 预警（超过一半时间没心跳）
            else if ($device->isOnline()) {
                $timeSinceHeartbeat = $now - $device->lastHeartbeat;
                if ($timeSinceHeartbeat > $this->heartbeatTimeout / 2) {
                    $this->log("设备心跳预警: {$deviceId}, 距离上次心跳: {$timeSinceHeartbeat}秒", 'WARNING');
                }
            }
        }

        // 定期保存缓存(每分钟一次)
        if ($now - $this->lastCacheSave >= $this->cacheSaveInterval) {
            $this->saveCache();
            $this->lastCacheSave = $now;
        }

        return $timeoutDevices;
    }

    /**
     * 检查并刷新即将过期的订阅
     * 在订阅过期前60秒自动刷新
     */
    public function checkAndRefreshSubscriptions() : void
    {
        try {
            $now = time();
            $refreshThreshold = 60; // 提前60秒刷新
            $needRefresh = [];

            foreach ($this->devices as $deviceId => $device) {
                if (!$device->isOnline()) {
                    continue;
                }

                // 清理过期订阅
                $device->cleanExpiredSubscriptions();

                $subscriptions = $device->getSubscriptions();
                foreach ($subscriptions as $eventType => $subInfo) {
                    $expiresAt = $subInfo['expires_at'] ?? 0;
                    if ($expiresAt > 0 && ($expiresAt - $now) <= $refreshThreshold) {
                        $needRefresh[] = [
                            'device_id'  => $deviceId,
                            'device'     => $device,
                            'event_type' => $eventType,
                            'expires_in' => $expiresAt - $now,
                            'params'     => $subInfo['params'] ?? [],
                        ];
                    }
                }
            }

            if (!empty($needRefresh)) {
                $this->log('检测到需要刷新的订阅: ' . count($needRefresh), 'INFO');

                foreach ($needRefresh as $item) {
                    $this->refreshSubscription(
                        $item['device'],
                        $item['event_type'],
                        $item['params']
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->log('检查订阅刷新失败: ' . $e->getMessage(), 'ERROR');
        }
    }

    /**
     * 刷新订阅
     */
    private function refreshSubscription(\Gb28181\GateWay\Device\Device $device, string $eventType, array $params) : void
    {
        try {
            $expires = 3600; // 默认续期1小时

            // 根据事件类型调用对应的订阅方法（通过Redis命令）
            $commandType = 'subscribe_' . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $eventType));

            $commandParams = array_merge($params, ['expires' => $expires]);

            // 通过Redis发送刷新命令
            $this->sendCommand($device->deviceId, $commandType, $commandParams);

            $this->log("发送订阅刷新请求: {$device->deviceId} - {$eventType}", 'INFO');
        } catch (\Throwable $e) {
            $this->log("刷新订阅失败: {$device->deviceId} - {$eventType}: {$e->getMessage()}", 'ERROR');
        }
    }

    /**
     * 通过Redis发送命令
     */
    private function sendCommand(string $deviceId, string $action, array $params) : void
    {
        $redis = $this->container['redis'];
        $command = [
            'device_id'  => $deviceId,
            'action'     => $action,
            'params'     => $params,
            'request_id' => uniqid(),
            'timestamp'  => time(),
        ];

        $redis->lPush($this->config['redis']['queue_name'] ?? 'gb28181:commands', json_encode($command));
    }

    /**
     * 获取所有在线设备
     *
     * @return array
     */
    public function getOnlineDevices() : array
    {
        return array_map(
            fn($device) => $device->toArray(),
            array_filter($this->devices, fn($device) => $device->isOnline())
        );
    }

    /**
     * 获取设备状态信息
     *
     * @param string $deviceId
     * @return array|null
     */
    public function getDeviceStatus(string $deviceId) : ?array
    {
        $device = $this->devices[$deviceId] ?? null;
        return $device ? $device->toArray() : null;
    }

    /**
     * 获取在线设备数量（轻量，不创建数组）
     */
    public function countOnline() : int
    {
        $count = 0;
        foreach ($this->devices as $device) {
            if ($device->isOnline()) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 获取所有设备列表
     *
     * @return array
     */
    public function getAllDevices() : array
    {
        return array_map(fn($device) => $device->toArray(), $this->devices);
    }

    /**
     * 获取设备统计
     *
     * @return array
     */
    public function getStats() : array
    {
        $created = 0;
        $starting = 0;
        $online = 0;
        $offline = 0;
        $timeout = 0;
        $stopped = 0;

        foreach ($this->devices as $device) {
            switch ($device->status) {
                case 'created':
                    $created++;
                    break;
                case 'starting':
                    $starting++;
                    break;
                case 'online':
                    $online++;
                    break;
                case 'unregistered':
                    $offline++;
                    break;
                case 'timeout':
                    $timeout++;
                    break;
                case 'stopped':
                    $stopped++;
                    break;
            }
        }

        return [
            'total'        => count($this->devices),
            'created'      => $created,
            'starting'     => $starting,
            'online'       => $online,
            'unregistered' => $offline,
            'timeout'      => $timeout,
            'stopped'      => $stopped,
        ];
    }

    /**
     * 清理长时间离线的设备
     *
     * @param int $days 离线多少天后清理
     * @return array 被清理的设备列表 [deviceId => deviceData]
     */
    public function cleanupOfflineDevices(int $days = 7) : array
    {
        $threshold = time() - ($days * 86400);
        $cleanedDevices = [];

        foreach ($this->devices as $deviceId => $device) {
            if ($device->status === 'stopped' || $device->status === 'unregistered') {
                $lastTime = $device->registerTime;

                if ($lastTime > 0 && $lastTime < $threshold) {
                    // 保存设备信息用于通知
                    $cleanedDevices[$deviceId] = [
                        'device_id'      => $deviceId,
                        'ip'             => $device->ip,
                        'port'           => $device->port,
                        'registered_at'  => $device->registeredAt,
                        'last_heartbeat' => $device->lastHeartbeat,
                        'status'         => $device->status,
                    ];

                    $this->removeDevice($deviceId);
                }
            }
        }

        if (count($cleanedDevices) > 0) {
            $this->log("清理 " . count($cleanedDevices) . " 个长期离线设备");
        }

        return $cleanedDevices;
    }

    /**
     * 加载设备列表（启动时调用）
     * 优先级：API > 本地缓存 > 空启动
     */
    private function loadDevices() : void
    {
        // 1. 优先从API拉取(最新数据)
        if ($this->apiLoader && $devices = ($this->apiLoader)()) {
            $loadedCount = 0;
            foreach ($devices as $deviceData) {
                $deviceId = $deviceData['device_id'] ?? '';
                if (!$deviceId) continue;

                try {
                    // 重建Device对象
                    $device = new Device($deviceId, $deviceData);
                    $device->markRegistered();
                    $device->lastHeartbeat = $deviceData['last_heartbeat_at'] ?? time();
                    $device->registeredAt = $deviceData['registered_at'] ?? time();
                    $device->status = 'online';

                    // 恢复通道列表
                    if (isset($deviceData['channels']) && is_array($deviceData['channels'])) {
                        $device->setChannels($deviceData['channels']);
                    }

                    $this->devices[$deviceId] = $device;
                    $loadedCount++;
                } catch (\Exception $e) {
                    $this->log("恢复设备失败 {$deviceId}: {$e->getMessage()}", 'WARNING');
                }
            }

            $this->log("从API加载 {$loadedCount} 个在线设备");

            // 保存到本地缓存(下次启动兜底)
            $this->saveCache();
            return;
        }

        // 2. API失败,从本地缓存恢复
        if (file_exists($this->cacheFile)) {
            $cached = json_decode(file_get_contents($this->cacheFile), true);
            if ($cached && isset($cached['devices']) && is_array($cached['devices'])) {
                $loadedCount = 0;
                foreach ($cached['devices'] as $deviceData) {
                    $deviceId = $deviceData['device_id'] ?? '';
                    if (!$deviceId) continue;

                    try {
                        $device = new Device($deviceId, $deviceData);
                        $device->markRegistered();
                        $device->lastHeartbeat = $deviceData['last_heartbeat'] ?? time();
                        $device->registeredAt = $deviceData['registered_at'] ?? time();
                        $device->status = 'online';

                        // 恢复通道列表
                        if (isset($deviceData['channels']) && is_array($deviceData['channels'])) {
                            $device->setChannels($deviceData['channels']);
                        }

                        $this->devices[$deviceId] = $device;
                        $loadedCount++;
                    } catch (\Exception $e) {
                        $this->log("恢复缓存设备失败 {$deviceId}: {$e->getMessage()}", 'WARNING');
                    }
                }

                $cacheTime = date('Y-m-d H:i:s', $cached['timestamp'] ?? 0);
                $this->log("从缓存恢复 {$loadedCount} 个设备 (缓存时间: {$cacheTime})");
                $this->log("  API不可用,使用本地缓存(可能过期)", 'WARNING');
                return;
            }
        }

        // 3. 全失败,空启动
        $this->log("  无法加载设备数据,以空列表启动", 'WARNING');
    }

    /**
     * 保存设备缓存到本地文件
     */
    private function saveCache() : void
    {
        try {
            $fp = fopen($this->cacheFile . '.tmp', 'w');
            if (!$fp) {
                $this->log("无法打开缓存文件", 'ERROR');
                return;
            }

            // 先统计在线设备数
            $count = 0;
            foreach ($this->devices as $device) {
                if ($device->isOnline()) {
                    $count++;
                }
            }

            // 流式写入 JSON
            fwrite($fp, '{"timestamp":' . time() . ',"count":' . $count . ',"devices":[');

            $first = true;
            foreach ($this->devices as $deviceId => $device) {
                /**@var Device $device */
                if (!$device->isOnline()) {
                    continue;
                }

                $data = [
                    'device_id'            => $device->deviceId,
                    'uri'                  => $device->uri,
                    'ip'                   => $device->ip,
                    'port'                 => $device->port,
                    'user_agent'           => $device->info['user_agent'] ?? '',
                    'registered_at'        => $device->registeredAt,
                    'last_heartbeat'       => $device->lastHeartbeat,
                    'expires'              => $device->expires,
                    'channels'             => $device->channels,
                    'rtp_trans_mode'       => $device->rtpTransMode,
                    'subscribe_catalog'    => $device->subscribeCatalog,
                    'subscribe_alarm'      => $device->subscribeAlarm,
                    'subscribe_position'   => $device->subscribePosition,
                    'subscribe_ptz'        => $device->subscribePtz,
                    'subscribe_expires'    => $device->subscribeExpires,
                    'position_interval'    => $device->positionInterval,
                    'catalog_interval'     => $device->catalogInterval,
                    'last_catalog_at'      => $device->lastCatalogAt,
                    'charset'              => $device->charset,
                    'stream_index'         => $device->streamIndex,
                    'filter_channel_types' => $device->filterChannelTypes,
                    'record_mode'          => $device->recordMode,
                    'catalog_structure'    => $device->catalogStructure,
                    'subscription_status'  => $device->subscriptions,
                ];

                if (!$first) {
                    fwrite($fp, ',');
                }
                $first = false;

                // 逐设备编码写入，不累积整个数组
                fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
            }

            fwrite($fp, ']}');
            fclose($fp);

            // 原子替换
            rename($this->cacheFile . '.tmp', $this->cacheFile);
        } catch (\Exception $e) {
            $this->log("保存缓存失败: {$e->getMessage()}", 'ERROR');
        }
    }

    /**
     * 日志输出
     */
    private function log(string $message, string $level = 'INFO') : void
    {
        $this->logger->log($message, $level, 'DeviceManager');
    }

    // ==================== 订阅管理 ====================

    /**
     * 添加订阅
     *
     * @param string $deviceId 设备ID
     * @param string $type 订阅类型（mobile_position/catalog等）
     * @param array $subscription 订阅信息
     */
    public function addSubscription(string $deviceId, string $type, array $subscription) : void
    {
        $device = $this->devices[$deviceId] ?? null;
        if (!$device) {
            $this->log("设备不存在，无法添加订阅: {$deviceId}", 'WARNING');
            return;
        }

        if (!isset($device->subscriptions)) {
            $device->subscriptions = [];
        }

        $device->subscriptions[$type] = $subscription;
        $this->log("订阅已添加: {$deviceId}, 类型: {$type}");
    }

    /**
     * 移除订阅
     *
     * @param string $deviceId 设备ID
     * @param string $type 订阅类型
     */
    public function removeSubscription(string $deviceId, string $type) : void
    {
        $device = $this->devices[$deviceId] ?? null;
        if (!$device) {
            return;
        }

        if (isset($device->subscriptions[$type])) {
            unset($device->subscriptions[$type]);
            $this->log("订阅已移除: {$deviceId}, 类型: {$type}");
        }
    }

    /**
     * 获取订阅信息
     *
     * @param string $deviceId 设备ID
     * @param string $type 订阅类型
     * @return array|null
     */
    public function getSubscription(string $deviceId, string $type) : ?array
    {
        $device = $this->devices[$deviceId] ?? null;
        if (!$device || !isset($device->subscriptions)) {
            return null;
        }

        return $device->subscriptions[$type] ?? null;
    }

    /**
     * 检查订阅是否过期
     *
     * @param string $deviceId 设备ID
     * @param string $type 订阅类型
     * @return bool
     */
    public function isSubscriptionExpired(string $deviceId, string $type) : bool
    {
        $subscription = $this->getSubscription($deviceId, $type);
        if (!$subscription) {
            return true;
        }

        $expireTime = $subscription['expire_time'] ?? 0;
        return time() >= $expireTime;
    }
}
