<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface PresetDao extends AdvancedDaoInterface
{
    public function getByDeviceAndChannelAndValue(string $deviceId, string $channelId, int $value);

    public function findByDeviceAndChannel(string $deviceId, string $channelId);

    public function deleteByDeviceAndChannel(string $deviceId, string $channelId, ?int $value = null);
}
