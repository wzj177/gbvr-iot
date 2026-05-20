<?php

namespace CoreW\Business\Subscribe\Dao\Impl;

use CoreW\Business\Subscribe\Dao\DeviceSubscribeConfigDao;
use CoreW\Dao\AdvancedDaoImpl;

class DeviceSubscribeConfigDaoImpl extends AdvancedDaoImpl implements DeviceSubscribeConfigDao
{
    protected $table = 'gv_device_subscribe_config';

    public function declares() : array
    {
        return [
            'serializes' => [
            ],
            'orderbys'   => [
                'id',
                'created_at',
                'last_subscribed_at',
                'subscription_expires_at',
            ],
            'datetime'   => [
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
                'channel_id IS NULL',
                'status = :status',
                'status IN (:statuses)',
                'event_catalog = :event_catalog',
                'event_alarm = :event_alarm',
                'event_mobile_position = :event_mobile_position',
                'subscription_expires_at < :expires_before',
                'subscription_expires_at > :expires_after',
                'auto_renew = :auto_renew',
            ],
        ];
    }

    public function getByDeviceAndChannel(string $deviceId, ?string $channelId = null) : ?array
    {
        $conditions = ['device_id' => $deviceId];
        if ($channelId === null) {
            // 查找 channel_id IS NULL 的记录
            $sql = "SELECT * FROM {$this->table()}
                    WHERE device_id = ?
                    AND channel_id IS NULL
                    LIMIT 1";
            return $this->db()->fetchAssoc($sql, [$deviceId]) ? : null;
        } else {
            $conditions['channel_id'] = $channelId;
        }

        return $this->getByFields($conditions);
    }

    public function findExpiringConfigs(string $expireTime) : array
    {
        return $this->search(
            [
                'status'                    => 1,
                'auto_renew'                => 1,
                'subscription_expires_at <' => $expireTime,
            ],
            ['subscription_expires_at' => 'ASC'],
            0,
            1000
        );
    }
}
