<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface DeviceDao extends AdvancedDaoInterface
{
    public function getByDeviceId(string $deviceId);

    /**
     * 获取推送设备列表（包含订阅配置）
     *
     * @param array $deviceIds 设备ID列表
     * @return array 设备列表，每个设备包含 subscription_status 字段
     */
    public function getDevicesForPush(array $deviceIds): array;
}
