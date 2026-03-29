<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface DeviceChannelsDao extends AdvancedDaoInterface
{
    public function getByDeviceAndChannel(string $deviceId, string $channelId);
    public function getByMainId(string $mainId);

    public function existBySsrc(string $ssrc);

    public function findByDeviceId(string $deviceId): array;

    public function deleteByDeviceId(string $deviceId): int|string;

    /**
     * 更新设备下所有通道的位置信息（设备同步的经纬度）
     *
     * @param string $deviceId 设备ID
     * @param float $longitude 经度
     * @param float $latitude 纬度
     * @return int 更新的通道数量
     */
    public function updatePositionByDeviceId(string $deviceId, float $longitude, float $latitude): int;
}
