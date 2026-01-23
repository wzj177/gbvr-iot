<?php

namespace CoreW\Business\Alarm\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface AlarmEventDao extends AdvancedDaoInterface
{
    /**
     * 根据设备和通道查询最新的事件
     */
    public function getLatestByDeviceAndChannel(string $deviceId, string $channelId): ?array;
}
