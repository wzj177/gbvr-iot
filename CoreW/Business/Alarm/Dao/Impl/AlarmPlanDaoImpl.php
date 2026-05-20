<?php

namespace CoreW\Business\Alarm\Dao\Impl;

use CoreW\Business\Alarm\Dao\AlarmPlanDao;
use CoreW\Dao\AdvancedDaoImpl;

class AlarmPlanDaoImpl extends AdvancedDaoImpl implements AlarmPlanDao
{
    protected $table = 'gv_alarm_plan';

    public function declares() : array
    {
        return [
            'serializes' => [
                'alarm_level'     => 'json',
                'alarm_method'    => 'json',
                'alarm_type'      => 'json',
                'alarm_eventtype' => 'json',
            ],
            'orderbys'   => [
                'id',
                'created_at',
            ],
            'timestamps' => [
                'created_at',
                'updated_at',
            ],
            'datetime'   => [
                'created_at',
                'updated_at',
            ],
            'conditions' => [
                'id = :id',
                'id IN (:ids)',
                'status = :status',
                'name LIKE :nameLike',
            ],
        ];
    }

    public function getEnabledPlans() : array
    {
        return $this->search(['status' => 1]);
    }

    public function getPlansByDeviceAndChannel(string $deviceId, string $channelId) : array
    {
        $sql = "SELECT p.*
                FROM {$this->table()} p
                INNER JOIN gv_alarm_plan_channel pc ON pc.alarm_plan_id = p.id
                WHERE pc.device_id = ?
                AND pc.channel_id = ?
                AND pc.enabled = 1
                AND p.status = 1";

        return $this->db()->fetchAll($sql, [$deviceId, $channelId]);
    }
}
