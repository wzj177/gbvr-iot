<?php

namespace CoreW\Business\Subscribe\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface DeviceSubscribeConfigDao extends AdvancedDaoInterface
{
    /**
     * 根据设备和通道ID查询订阅配置
     */
    public function getByDeviceAndChannel(string $deviceId, ?string $channelId = null): ?array;

    /**
     * 查找即将过期的订阅配置
     */
    public function findExpiringConfigs(string $expireTime): array;
}
