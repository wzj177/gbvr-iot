<?php

namespace CoreW\Business\Subscribe\Service;

interface SubscribeService
{
    /**
     * 创建或更新订阅配置
     */
    public function saveSubscribeConfig(string $deviceId, ?string $channelId, array $config): array;

    /**
     * 批量创建订阅配置
     */
    public function batchCreateSubscribeConfigs(array $deviceIds, array $defaultConfig): int;

    /**
     * 下发订阅到网关
     */
    public function applySubscribeConfig(array $subscribeConfig): bool;

    /**
     * 取消订阅
     */
    public function cancelSubscribe(int $configId): bool;

    /**
     * 续订即将过期的订阅
     * 
     * @param string $expireTime 过期时间
     * @return int 续订数量
     */
    public function renewExpiringSubscriptions(string $expireTime): int;

    /**
     * 查询订阅配置
     */
    public function getSubscribeConfig(string $deviceId, ?string $channelId = null): ?array;

    /**
     * 搜索订阅配置
     */
    public function searchSubscribeConfigs(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20): array;

    /**
     * 统计订阅配置数量
     */
    public function countSubscribeConfigs(array $conditions): int;

    /**
     * 更新订阅的 dialog_id
     * 当收到 SUBSCRIBE 200 OK 响应时调用
     *
     * @param string $deviceId 设备ID
     * @param string $eventType 事件类型: catalog, alarm, mobilePosition
     * @param int $dialogId 对话ID
     * @param int $subscriptionId 订阅ID
     * @param int $expires 过期时间（秒）
     * @return bool 是否更新成功
     */
    public function updateDialogId(string $deviceId, string $eventType, int $dialogId, int $subscriptionId, int $expires): bool;

    /**
     * 更新订阅过期时间（续订成功时调用）
     *
     * @param string $deviceId 设备ID
     * @param string $eventType 事件类型
     * @param int $dialogId 对话ID
     * @param int $expires 新的过期时间（秒）
     * @return bool 是否更新成功
     */
    public function updateSubscriptionExpires(string $deviceId, string $eventType, int $dialogId, int $expires): bool;

    /**
     * 标记订阅为已过期/失效（续订失败时调用）
     *
     * @param string $deviceId 设备ID
     * @param string $eventType 事件类型
     * @param int $dialogId 对话ID
     * @return bool 是否更新成功
     */
    public function markSubscriptionExpired(string $deviceId, string $eventType, int $dialogId): bool;
}
