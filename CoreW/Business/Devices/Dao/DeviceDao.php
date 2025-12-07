<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface DeviceDao extends AdvancedDaoInterface
{
    public function getByDeviceId(string $deviceId);
}
