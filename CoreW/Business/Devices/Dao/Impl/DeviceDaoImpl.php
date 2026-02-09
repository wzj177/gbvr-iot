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

    public function getDevicesForPush(array $deviceIds): array
    {
        if (empty($deviceIds)) {
            return [];
        }

        // 查询设备列表
        $devices = $this->db()->fetchAll(
            "SELECT device_id, ip, port, from_uri, user_agent, registered_at, last_heartbeat_at, expires
             FROM {$this->table}
             WHERE device_id IN (:device_ids)",
            ['device_ids' => $deviceIds]
        );

        if (empty($devices)) {
            return [];
        }

        // 查询设备级订阅配置
        $subscriptionConfigs = $this->db()->fetchAll(
            "SELECT device_id, event_catalog, event_alarm, event_mobile_position,
                    alarm_priority_min, alarm_priority_max, mobile_interval_sec,
                    catalog_dialog_id, alarm_dialog_id, position_dialog_id,
                    subscription_expires_at
             FROM gv_device_subscribe_config
             WHERE device_id IN (:device_ids) AND channel_id IS NULL",
            ['device_ids' => $deviceIds]
        );

        // 按设备ID索引订阅配置
        $configMap = [];
        foreach ($subscriptionConfigs as $config) {
            $configMap[$config['device_id']] = $config;
        }

        // 组合数据
        foreach ($devices as &$device) {
            $deviceId = $device['device_id'];
            if (isset($configMap[$deviceId])) {
                $config = $configMap[$deviceId];
                $device['subscription_status'] = $this->buildSubscriptionStatus($config);
            }
        }

        return $devices;
    }

    /**
     * 构建订阅状态数据
     */
    private function buildSubscriptionStatus(array $config): array
    {
        $status = [];

        if ($config['event_catalog']) {
            $status['catalog'] = [
                'enabled' => true,
                'dialog_id' => $config['catalog_dialog_id'],
                'expires_at' => $config['subscription_expires_at'] ? strtotime($config['subscription_expires_at']) : null,
            ];
        }

        if ($config['event_alarm']) {
            $status['alarm'] = [
                'enabled' => true,
                'dialog_id' => $config['alarm_dialog_id'],
                'priority_min' => $config['alarm_priority_min'],
                'priority_max' => $config['alarm_priority_max'],
                'expires_at' => $config['subscription_expires_at'] ? strtotime($config['subscription_expires_at']) : null,
            ];
        }

        if ($config['event_mobile_position']) {
            $status['mobile_position'] = [
                'enabled' => true,
                'dialog_id' => $config['position_dialog_id'],
                'interval' => $config['mobile_interval_sec'],
                'expires_at' => $config['subscription_expires_at'] ? strtotime($config['subscription_expires_at']) : null,
            ];
        }

        return $status;
    }

    public function declares(): array
    {
        return [
            'serializes' => [
                'filter_channel_types' => 'json'
            ],
            'orderbys' => [
                'id',
                'status'
            ],
            'timestamps' => [
                'last_heartbeat_at',
                'last_catalog_at',
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
                'device_id NOT IN (:noDevice_ids)',
                'status = :status',
                'subscribe_catalog = :subscribe_catalog',
                'subscribe_alarm = :subscribe_alarm',
                'subscribe_position = :subscribe_position',
                'subscribe_ptz = :subscribe_ptz',
            ],
        ];
    }
}
