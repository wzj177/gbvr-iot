<?php

namespace CoreW\Business\Alarm\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface AlarmPlanDao extends AdvancedDaoInterface
{
    /**
     * 查询所有启用的报警预案
     */
    public function getEnabledPlans(): array;

    /**
     * 根据设备和通道查询关联的报警预案
     */
    public function getPlansByDeviceAndChannel(string $deviceId, string $channelId): array;
}
