<?php

namespace CoreW\Business\Devices\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Devices\Dao\AlarmDao;

class AlarmDaoImpl extends AdvancedDaoImpl implements AlarmDao
{

    protected $table = 'gv_alarm_event';

    public function declares(): array
    {
        return [
            'serializes' => [
                'alarm_level' => 'json',
                'alarm_method' => 'json',
                'alarm_type' => 'json',
                'alarm_eventtype' => 'json',
            ],
            'orderbys' => [
                'id',
                'alarm_time',
                'recv_time',
                'created_at',
            ],
            'timestamps' => [
                'created_at',
                'updated_at',
            ],
            'datetime' => [
                'alarm_time',
                'recv_time',
                'created_at',
                'updated_at',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'device_id = :device_id',
                'device_id IN (:device_ids)',
                'channel_id = :channel_id',
                'channel_id IN (:channel_ids)',
                'level = :level',
                'level IN (:levels)',
                'method = :method',
                'method IN (:methods)',
                'type = :type',
                'type IN (:types)',
                'eventtype = :eventtype',
                'alarm_plan_id = :alarm_plan_id',
                'alarm_plan_id IS NULL',
                'alarm_time > :alarm_time_after',
                'alarm_time >= :alarm_time_gte',
                'alarm_time < :alarm_time_before',
                'alarm_time <= :alarm_time_lte',
                'alarm_time >= :start_time',
                'alarm_time <= :end_time',
                'recv_time > :recv_time_after',
                'recv_time >= :recv_time_gte',
                'recv_time < :recv_time_before',
                'recv_time <= :recv_time_lte',
            ],
        ];
    }

    public function create($fields)
    {
        if (empty($fields['created_at'])) {
            $fields['created_at'] = date('Y-m-d H:i:s');
        }
        if (empty($fields['updated_at'])) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
        }

        return parent::create($fields);
    }
}
