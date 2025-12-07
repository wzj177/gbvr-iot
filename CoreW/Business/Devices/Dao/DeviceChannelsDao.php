<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface DeviceChannelsDao extends AdvancedDaoInterface
{
    public function getByDeviceAndChannel(string $deviceId, string $channelId);

    public function existBySsrc(string $ssrc);

    public function findByDeviceId(string $deviceId): array;

    public function deleteByDeviceId(string $deviceId): int|string;
}
