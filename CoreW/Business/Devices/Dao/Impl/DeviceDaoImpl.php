<?php

namespace CoreW\Business\Devices\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Devices\Dao\DeviceDao;

class DeviceDaoImpl extends AdvancedDaoImpl implements DeviceDao
{

    protected $table = 'gv_devices';

    public function getByDeviceId(string $deviceId)
    {
        return $this->getByFields([
            'device_id' => $deviceId
        ]);
    }

    public function declares(): array
    {
        return [
            'serializes' => [
                'filter_channel_types' => 'json'
            ],
            'orderbys' => [
                'id',
            ],
            'timestamps' => [
                'last_heartbeat_at',
                'last_catalog_at',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'device_id = :device_id',
                'device_id IN (:device_ids)',
                'device_id NOT IN (:noDevice_ids)',
                'status = :status',
            ],
        ];
    }
}
