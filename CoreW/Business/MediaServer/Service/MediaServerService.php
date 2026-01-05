<?php

namespace CoreW\Business\MediaServer\Service;

use CoreW\Business\MediaServer\Strategy\MediaServerStrategyInterface;

interface MediaServerService
{
    public function getMediaServerById($id);

    public function getMediaServerByServerId(string $serverId): ?array;

    public function countMediaServers(array $conditions);

    public function searchMediaServers(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function getSimpleList(): array;

    public function createMediaServer(array $fields);

    public function updateMediaServer($id, array $fields);

    public function deleteMediaServerById($id);

    /**
     * 获取服务器统计信息
     */
    public function getStats(int $id): array;

    /**
     * 获取服务器配置
     */
    public function getConfig(int $id): array;

    /**
     * 设置服务器配置
     */
    public function setConfig(int $id, array $config): bool;

    /**
     * 重启服务器
     */
    public function restart(int $id): bool;

    /**
     * 同步服务器状态
     */
    public function syncStatus(int $id): bool;

    /**
     * 根据类型获取策略实例
     */
    public function getStrategy(string $type): MediaServerStrategyInterface;

}
