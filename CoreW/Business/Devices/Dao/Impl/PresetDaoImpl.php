<?php

namespace CoreW\Business\Devices\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Devices\Dao\PresetDao;

class PresetDaoImpl extends AdvancedDaoImpl implements PresetDao
{

    protected $table = 'gv_device_presets';

    public function getByDeviceAndChannelAndValue(string $deviceId, string $channelId, int $value)
    {
        return $this->getByFields([
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'value' => $value,
        ]);
    }

    public function findByDeviceAndChannel(string $deviceId, string $channelId)
    {
        return $this->search(
            [
                'device_id' => $deviceId,
                'channel_id' => $channelId,
            ],
            ['value' => 'ASC'],
            0,
            255
        );
    }

    public function deleteByDeviceAndChannel(string $deviceId, string $channelId, ?int $value = null): int
    {
        $conditions = [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
        ];

        if ($value !== null) {
            $conditions['value'] = $value;
        }

        return $this->db()->delete($this->table, $conditions);
    }

    public function declares(): array
    {
        return [
            'serializes' => [
            ],
            'orderbys' => [
                'id',
                'value',
            ],
            'datetime' => [
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
                'value = :value',
                'value IN (:values)',
                'status = :status',
            ],
            'timestamps' => [
                'created_at',
                'updated_at',
            ],
        ];
    }
}
