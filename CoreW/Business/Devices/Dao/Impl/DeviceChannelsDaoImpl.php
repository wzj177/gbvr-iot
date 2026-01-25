<?php

namespace CoreW\Business\Devices\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Devices\Dao\DeviceChannelsDao;

class DeviceChannelsDaoImpl extends AdvancedDaoImpl implements DeviceChannelsDao
{

    protected $table = 'gv_device_channels';

    public function getByDeviceAndChannel(string $deviceId, string $channelId)
    {
        return $this->getByFields([
            'device_id' => $deviceId,
            'channel_id' => $channelId
        ]);
    }

    public function getByMainId(string $mainId)
    {
        return $this->getByFields([
            'main_id' => $mainId
        ]);
    }

    public function findByDeviceId(string $deviceId): array
    {
        return $this->findByFields([
            'device_id' => $deviceId
        ]);
    }

    /**
     * 删除设备下的所有频道
     * @param string $deviceId
     * @return int|string
     * @throws \Doctrine\DBAL\Exception
     */
    public function deleteByDeviceId(string $deviceId): int|string
    {
        return  $this->db()->delete($this->table(), ['device_id' => $deviceId]);
    }

    public function existBySsrc(string $ssrc): bool
    {
        return $this->getByFields([
                'ssrc' => $ssrc
            ]) !== null;
    }

    public function declares(): array
    {
        return [
            'serializes' => [
            ],
            'orderbys' => [
                'id',
                'status'
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'device_id IN (:device_ids)',
                'device_id = :device_id',
                'status = :status',
                'channel_type = :channel_type',
                'channel_type IN (:channel_types)',
                'channel_id = :channel_id',
                '(channel_name LIKE :keywords OR show_name LIKE :keywords OR channel_id LIKE :keywords)',
            ],
        ];
    }
}
