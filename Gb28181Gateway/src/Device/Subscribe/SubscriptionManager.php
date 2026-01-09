<?php

namespace Gb28181\GateWay\Device\Subscribe;

use Gb28181\GateWay\Libs\Logger;
use Gb28181\GateWay\Device\Device;

/**
 * GB28181 订阅管理器 - PHP 层管理订阅状态
 * 
 * 职责分离：
 * - C 层：纯 SIP 协议操作（发送 SUBSCRIBE/REFRESH/CANCEL，响应 NOTIFY）
 * - PHP 层：业务状态管理（存储、查询、自动刷新、过期清理）
 * 
 * 存储方案：
 * - Redis Hash: subscription:{deviceId}:{eventType} → {subscription_id, dialog_id, expires, ...}
 * - Redis ZSet: subscription:refresh_queue → {score=next_refresh_time, member=key}
 * 
 * 优势：
 * - 无订阅数量限制（Redis 支持上万甚至百万级）
 * - 持久化（进程重启不丢失）
 * - 分布式（多节点共享订阅状态）
 * - 高效查询（O(1) 哈希查找）
 */
class SubscriptionManager
{
    private $sipServer;      // ExoSip 实例
    private $redis;          // Redis 连接
    private Logger $logger;
    private array $config;
    
    // Redis Key 前缀
    const KEY_PREFIX = 'gb28181:subscription';
    const REFRESH_QUEUE = 'gb28181:subscription:refresh_queue';
    
    public function __construct($sipServer, $redis, array $config = [])
    {
        $this->sipServer = $sipServer;
        $this->redis = $redis;
        $this->logger = Logger::getInstance();
        $this->config = array_merge([
            'auto_refresh' => true,        // 自动刷新订阅
            'refresh_advance' => 300,      // 提前 5 分钟刷新
            'default_expires' => 3600,     // 默认有效期 1 小时
            'debug' => false,
        ], $config);
    }
    
    /**
     * 订阅事件
     * 
     * @param Device $device 设备对象
     * @param string $eventType 事件类型: Catalog/Alarm/MobilePosition
     * @param int $expires 有效期（秒）
     * @param array $params 扩展参数（如 interval, priority）
     * @return array ['subscription_id' => int, 'device_id' => string, 'event_type' => string]
     * @throws \Exception
     */
    public function subscribe(Device $device, string $eventType, int $expires = 3600, array $params = []): array
    {
        $deviceId = $device->deviceId;
        $ip = $device->received_ip ?? $device->ip;
        $port = $device->received_port ?? $device->port;
        
        // 1. 构建目标 URI
        $toUri = "sip:{$deviceId}@{$ip}:{$port}";
        
        // 2. 构建 GB28181 订阅 XML Body（如果需要）
        $xmlBody = $this->buildSubscribeXml($eventType, $deviceId, $params);
        
        if ($this->config['debug']) {
            $this->logger->debug('发送订阅请求', [
                'device_id' => $deviceId,
                'to_uri' => $toUri,
                'event_type' => $eventType,
                'expires' => $expires,
                'xml_body' => $xmlBody
            ]);
        }
        
        // 3. 调用 C 扩展发送 SUBSCRIBE
        $subscriptionId = $this->sipServer->subscribe($toUri, $eventType, $expires, $xmlBody);
        
        if ($subscriptionId === false || $subscriptionId < 0) {
            throw new \RuntimeException("Failed to send SUBSCRIBE to device {$deviceId}");
        }
        
        // 4. 存储订阅信息到 Redis
        $key = $this->getSubscriptionKey($deviceId, $eventType);
        $now = time();
        
        $this->redis->hMSet($key, [
            'subscription_id' => $subscriptionId,
            'dialog_id' => -1,                      // 等待 200 OK 后更新
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'expires' => $expires,
            'created_at' => $now,
            'last_refresh' => $now,
            'next_refresh' => $now + $expires - $this->config['refresh_advance'],
            'ip' => $ip,
            'port' => $port,
            'params' => json_encode($params),
        ]);
        
        // 5. 加入刷新队列（使用 ZSet 按时间排序）
        if ($this->config['auto_refresh']) {
            $nextRefreshTime = $now + $expires - $this->config['refresh_advance'];
            $this->redis->zAdd(self::REFRESH_QUEUE, $nextRefreshTime, $key);
        }
        
        // 6. 设置过期时间（防止僵尸订阅）
        $this->redis->expire($key, $expires + 600);  // 比订阅过期晚 10 分钟
        
        $this->logger->info('订阅创建成功', [
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'subscription_id' => $subscriptionId,
            'expires' => $expires
        ]);
        
        return [
            'subscription_id' => $subscriptionId,
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'expires' => $expires,
        ];
    }
    
    /**
     * 更新订阅的 dialog_id（收到 200 OK 响应后调用）
     * 
     * @param int $subscriptionId C 层返回的订阅 ID
     * @param int $dialogId Dialog ID
     */
    public function updateDialogId(int $subscriptionId, int $dialogId): void
    {
        // 从 Redis 查找订阅
        $keys = $this->redis->keys(self::KEY_PREFIX . ':*');
        
        foreach ($keys as $key) {
            $info = $this->redis->hGetAll($key);
            if (isset($info['subscription_id']) && $info['subscription_id'] == $subscriptionId) {
                $this->redis->hSet($key, 'dialog_id', $dialogId);
                
                if ($this->config['debug']) {
                    $this->logger->debug('更新 dialog_id', [
                        'subscription_id' => $subscriptionId,
                        'dialog_id' => $dialogId,
                        'device_id' => $info['device_id'] ?? 'unknown',
                        'event_type' => $info['event_type'] ?? 'unknown'
                    ]);
                }
                break;
            }
        }
    }
    
    /**
     * 取消订阅
     * 
     * @param Device $device 设备对象
     * @param string $eventType 事件类型
     * @return bool
     */
    public function unsubscribe(Device $device, string $eventType): bool
    {
        $deviceId = $device->deviceId;
        $key = $this->getSubscriptionKey($deviceId, $eventType);
        
        // 1. 从 Redis 获取订阅信息
        $info = $this->redis->hGetAll($key);
        
        if (empty($info) || !isset($info['subscription_id'])) {
            $this->logger->warning('订阅不存在', [
                'device_id' => $deviceId,
                'event_type' => $eventType
            ]);
            return false;
        }
        
        $subscriptionId = (int)$info['subscription_id'];
        
        // 2. 调用 C 扩展取消订阅（发送 expires=0 的 SUBSCRIBE）
        $result = $this->sipServer->cancelSubscribe($subscriptionId);
        
        if (!$result) {
            $this->logger->error('取消订阅失败', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
                'subscription_id' => $subscriptionId
            ]);
            return false;
        }
        
        // 3. 从 Redis 删除订阅信息
        $this->redis->del($key);
        
        // 4. 从刷新队列移除
        $this->redis->zRem(self::REFRESH_QUEUE, $key);
        
        $this->logger->info('取消订阅成功', [
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'subscription_id' => $subscriptionId
        ]);
        
        return true;
    }
    
    /**
     * 刷新订阅（续订）
     * 
     * @param string $deviceId 设备 ID
     * @param string $eventType 事件类型
     * @return bool
     */
    public function refresh(string $deviceId, string $eventType): bool
    {
        $key = $this->getSubscriptionKey($deviceId, $eventType);
        
        // 1. 从 Redis 获取订阅信息
        $info = $this->redis->hGetAll($key);
        
        if (empty($info) || !isset($info['subscription_id'])) {
            $this->logger->warning('订阅不存在，无法刷新', [
                'device_id' => $deviceId,
                'event_type' => $eventType
            ]);
            return false;
        }
        
        $subscriptionId = (int)$info['subscription_id'];
        $expires = (int)($info['expires'] ?? $this->config['default_expires']);
        
        // 2. 调用 C 扩展刷新订阅
        $result = $this->sipServer->refreshSubscribe($subscriptionId, $expires);
        
        if (!$result) {
            $this->logger->error('刷新订阅失败', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
                'subscription_id' => $subscriptionId
            ]);
            return false;
        }
        
        // 3. 更新 Redis 中的时间戳
        $now = time();
        $this->redis->hMSet($key, [
            'last_refresh' => $now,
            'next_refresh' => $now + $expires - $this->config['refresh_advance'],
        ]);
        
        // 4. 更新刷新队列
        $nextRefreshTime = $now + $expires - $this->config['refresh_advance'];
        $this->redis->zAdd(self::REFRESH_QUEUE, $nextRefreshTime, $key);
        
        if ($this->config['debug']) {
            $this->logger->debug('刷新订阅成功', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
                'subscription_id' => $subscriptionId,
                'next_refresh' => date('Y-m-d H:i:s', $nextRefreshTime)
            ]);
        }
        
        return true;
    }
    
    /**
     * 自动刷新所有需要续订的订阅（定时器调用）
     * 
     * @return array ['refreshed' => int, 'failed' => int, 'total' => int]
     */
    public function autoRefreshAll(): array
    {
        $now = time();
        $refreshed = 0;
        $failed = 0;
        
        // 从 ZSet 获取需要刷新的订阅（分数 <= 当前时间）
        $keys = $this->redis->zRangeByScore(self::REFRESH_QUEUE, 0, $now);
        
        foreach ($keys as $key) {
            $info = $this->redis->hGetAll($key);
            
            if (empty($info) || !isset($info['device_id'], $info['event_type'])) {
                // 订阅信息不完整，从队列移除
                $this->redis->zRem(self::REFRESH_QUEUE, $key);
                $failed++;
                continue;
            }
            
            // 执行刷新
            if ($this->refresh($info['device_id'], $info['event_type'])) {
                $refreshed++;
            } else {
                $failed++;
            }
        }
        
        $result = [
            'refreshed' => $refreshed,
            'failed' => $failed,
            'total' => count($keys)
        ];
        
        if ($this->config['debug'] && count($keys) > 0) {
            $this->logger->debug('自动刷新订阅完成', $result);
        }
        
        return $result;
    }
    
    /**
     * 获取设备的订阅信息
     * 
     * @param string $deviceId 设备 ID
     * @param string|null $eventType 事件类型，null 返回所有订阅
     * @return array
     */
    public function getSubscription(string $deviceId, ?string $eventType = null): array
    {
        if ($eventType !== null) {
            // 获取单个订阅
            $key = $this->getSubscriptionKey($deviceId, $eventType);
            $info = $this->redis->hGetAll($key);
            
            if (empty($info)) {
                return [];
            }
            
            return $this->normalizeSubscriptionInfo($info);
        }
        
        // 获取设备的所有订阅
        $pattern = self::KEY_PREFIX . ":{$deviceId}:*";
        $keys = $this->redis->keys($pattern);
        
        $subscriptions = [];
        foreach ($keys as $key) {
            $info = $this->redis->hGetAll($key);
            if (!empty($info)) {
                $subscriptions[] = $this->normalizeSubscriptionInfo($info);
            }
        }
        
        return $subscriptions;
    }
    
    /**
     * 获取所有订阅信息（支持分页）
     * 
     * @param int $offset 偏移量
     * @param int $limit 限制数量
     * @return array
     */
    public function getAllSubscriptions(int $offset = 0, int $limit = 100): array
    {
        $pattern = self::KEY_PREFIX . ':*';
        $keys = $this->redis->keys($pattern);
        
        // 简单的内存分页（生产环境建议使用 SCAN）
        $keys = array_slice($keys, $offset, $limit);
        
        $subscriptions = [];
        foreach ($keys as $key) {
            $info = $this->redis->hGetAll($key);
            if (!empty($info)) {
                $subscriptions[] = $this->normalizeSubscriptionInfo($info);
            }
        }
        
        return $subscriptions;
    }
    
    /**
     * 统计订阅数量
     * 
     * @return array ['total' => int, 'by_type' => [...]]
     */
    public function getStatistics(): array
    {
        $pattern = self::KEY_PREFIX . ':*';
        $keys = $this->redis->keys($pattern);
        
        $total = count($keys);
        $byType = [];
        
        foreach ($keys as $key) {
            $info = $this->redis->hGetAll($key);
            if (isset($info['event_type'])) {
                $eventType = $info['event_type'];
                $byType[$eventType] = ($byType[$eventType] ?? 0) + 1;
            }
        }
        
        return [
            'total' => $total,
            'by_type' => $byType,
            'refresh_queue_size' => $this->redis->zCard(self::REFRESH_QUEUE),
        ];
    }
    
    /**
     * 清理过期订阅（定时任务调用）
     * 
     * @return array ['cleaned' => int]
     */
    public function cleanupExpired(): array
    {
        $now = time();
        $cleaned = 0;
        
        $pattern = self::KEY_PREFIX . ':*';
        $keys = $this->redis->keys($pattern);
        
        foreach ($keys as $key) {
            $info = $this->redis->hGetAll($key);
            
            if (empty($info) || !isset($info['created_at'], $info['expires'])) {
                // 数据不完整，直接删除
                $this->redis->del($key);
                $this->redis->zRem(self::REFRESH_QUEUE, $key);
                $cleaned++;
                continue;
            }
            
            $expiresAt = (int)$info['created_at'] + (int)$info['expires'];
            
            // 已过期超过 10 分钟
            if ($now > $expiresAt + 600) {
                $this->redis->del($key);
                $this->redis->zRem(self::REFRESH_QUEUE, $key);
                $cleaned++;
                
                if ($this->config['debug']) {
                    $this->logger->debug('清理过期订阅', [
                        'device_id' => $info['device_id'],
                        'event_type' => $info['event_type'],
                        'expires_at' => date('Y-m-d H:i:s', $expiresAt)
                    ]);
                }
            }
        }
        
        return ['cleaned' => $cleaned];
    }
    
    /**
     * 取消设备的所有订阅
     * 
     * @param Device $device 设备对象
     * @return array ['canceled' => int, 'failed' => int]
     */
    public function unsubscribeAll(Device $device): array
    {
        $deviceId = $device->deviceId;
        $pattern = self::KEY_PREFIX . ":{$deviceId}:*";
        $keys = $this->redis->keys($pattern);
        
        $canceled = 0;
        $failed = 0;
        
        foreach ($keys as $key) {
            $info = $this->redis->hGetAll($key);
            
            if (empty($info) || !isset($info['event_type'])) {
                $failed++;
                continue;
            }
            
            if ($this->unsubscribe($device, $info['event_type'])) {
                $canceled++;
            } else {
                $failed++;
            }
        }
        
        return [
            'canceled' => $canceled,
            'failed' => $failed,
            'total' => count($keys)
        ];
    }
    
    /**
     * 检查订阅是否存在
     * 
     * @param string $deviceId 设备 ID
     * @param string $eventType 事件类型
     * @return bool
     */
    public function exists(string $deviceId, string $eventType): bool
    {
        $key = $this->getSubscriptionKey($deviceId, $eventType);
        return $this->redis->exists($key) > 0;
    }
    
    // ==================== 私有方法 ====================
    
    /**
     * 生成 Redis Key
     */
    private function getSubscriptionKey(string $deviceId, string $eventType): string
    {
        return self::KEY_PREFIX . ":{$deviceId}:{$eventType}";
    }
    
    /**
     * 构建 GB28181 订阅 XML Body
     * 
     * @param string $eventType 事件类型
     * @param string $deviceId 设备 ID
     * @param array $params 扩展参数
     * @return string|null
     */
    private function buildSubscribeXml(string $eventType, string $deviceId, array $params): ?string
    {
        // Catalog 订阅不需要 XML Body
        if ($eventType === 'Catalog') {
            return null;
        }
        
        // Alarm 订阅（可选参数：优先级范围、报警方法）
        if ($eventType === 'Alarm') {
            $startPriority = $params['start_priority'] ?? 0;
            $endPriority = $params['end_priority'] ?? 3;
            $alarmMethod = $params['alarm_method'] ?? null;
            
            $xml = '<?xml version="1.0" encoding="GB2312"?>' . "\r\n";
            $xml .= '<Query>' . "\r\n";
            $xml .= '<CmdType>Alarm</CmdType>' . "\r\n";
            $xml .= '<SN>' . time() . '</SN>' . "\r\n";
            $xml .= '<DeviceID>' . $deviceId . '</DeviceID>' . "\r\n";
            $xml .= '<StartAlarmPriority>' . $startPriority . '</StartAlarmPriority>' . "\r\n";
            $xml .= '<EndAlarmPriority>' . $endPriority . '</EndAlarmPriority>' . "\r\n";
            if ($alarmMethod) {
                $xml .= '<AlarmMethod>' . $alarmMethod . '</AlarmMethod>' . "\r\n";
            }
            $xml .= '</Query>';
            
            return $xml;
        }
        
        // MobilePosition 订阅（必须参数：上报间隔）
        if ($eventType === 'MobilePosition') {
            $interval = $params['interval'] ?? 5;  // 默认 5 秒
            
            $xml = '<?xml version="1.0" encoding="GB2312"?>' . "\r\n";
            $xml .= '<Query>' . "\r\n";
            $xml .= '<CmdType>MobilePosition</CmdType>' . "\r\n";
            $xml .= '<SN>' . time() . '</SN>' . "\r\n";
            $xml .= '<DeviceID>' . $deviceId . '</DeviceID>' . "\r\n";
            $xml .= '<Interval>' . $interval . '</Interval>' . "\r\n";
            $xml .= '</Query>';
            
            return $xml;
        }
        
        return null;
    }
    
    /**
     * 标准化订阅信息格式
     */
    private function normalizeSubscriptionInfo(array $info): array
    {
        return [
            'subscription_id' => (int)($info['subscription_id'] ?? 0),
            'dialog_id' => (int)($info['dialog_id'] ?? -1),
            'device_id' => $info['device_id'] ?? '',
            'event_type' => $info['event_type'] ?? '',
            'expires' => (int)($info['expires'] ?? 0),
            'created_at' => (int)($info['created_at'] ?? 0),
            'last_refresh' => (int)($info['last_refresh'] ?? 0),
            'next_refresh' => (int)($info['next_refresh'] ?? 0),
            'ip' => $info['ip'] ?? '',
            'port' => (int)($info['port'] ?? 0),
            'params' => isset($info['params']) ? json_decode($info['params'], true) : [],
        ];
    }
}