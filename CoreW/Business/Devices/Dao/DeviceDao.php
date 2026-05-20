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
    public function getDevicesForPush(array $deviceIds) : array;

    /**
     * 更新移动设备的位置信息（设备同步的经纬度）
     *
     * @param string $deviceId 设备ID
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @return int 更新的设备数量（0或1）
     */
    public function updatePositionByDeviceId(string $deviceId, float $longitude, float $latitude) : int;
}
