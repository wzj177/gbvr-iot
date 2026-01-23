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
     */
    public function renewExpiringSubscriptions(): array;

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
}
