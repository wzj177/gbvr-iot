<?php

namespace CoreW\Business\Subscribe\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Subscribe\Service\SubscribeService;
use CoreW\Business\Subscribe\Dao\DeviceSubscribeConfigDao;
use CoreW\Dao\DaoProxy;
use support\Log;

class SubscribeServiceImpl extends BaseService implements SubscribeService
{
    // 订阅类型常量
    const EVENT_CATALOG = 'catalog';
    const EVENT_ALARM = 'alarm';
    const EVENT_MOBILE_POSITION = 'mobile_position';

    /**
     * 创建或更新订阅配置
     */
    public function saveSubscribeConfig(string $deviceId, ?string $channelId, array $config): array
    {
        // 验证参数
        $this->validateSubscribeConfig($config);

        // 检查设备是否存在
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);
        if (!$device) {
            throw new \InvalidArgumentException('Device not found');
        }

        // 查找现有配置
        $existing = $this->getDeviceSubscribeConfigDao()->getByDeviceAndChannel($deviceId, $channelId);

        $data = [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'event_catalog' => (int)($config['event_catalog'] ?? 0),
            'event_alarm' => (int)($config['event_alarm'] ?? 0),
            'event_mobile_position' => (int)($config['event_mobile_position'] ?? 0),
            'alarm_priority_min' => (int)($config['alarm_priority_min'] ?? 0),
            'alarm_priority_max' => (int)($config['alarm_priority_max'] ?? 4),
            'mobile_interval_sec' => (int)($config['mobile_interval_sec'] ?? 5),
            'subscribe_expires' => (int)($config['subscribe_expires'] ?? 3600),
            'auto_renew' => (int)($config['auto_renew'] ?? 1),
            'status' => (int)($config['status'] ?? 1),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing) {
            $this->getDeviceSubscribeConfigDao()->update($existing['id'], $data);
            $subscribeConfig = $this->getDeviceSubscribeConfigDao()->get($existing['id']);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $subscribeConfig = $this->getDeviceSubscribeConfigDao()->create($data);
        }

        // 如果启用，立即下发订阅
        if ($subscribeConfig['status'] == 1) {
            try {
                $this->applySubscribeConfig($subscribeConfig);
            } catch (\Exception $e) {
                Log::channel('sip')->error('下发订阅失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $subscribeConfig;
    }

    /**
     * 批量创建订阅配置
     */
    public function batchCreateSubscribeConfigs(array $deviceIds, array $defaultConfig): int
    {
        $count = 0;
        foreach ($deviceIds as $deviceId) {
            try {
                $this->saveSubscribeConfig($deviceId, null, $defaultConfig);
                $count++;
            } catch (\Exception $e) {
                Log::channel('sip')->error('批量创建订阅配置失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
            }
        }
        return $count;
    }

    /**
     * 下发订阅到网关
     */
    public function applySubscribeConfig(array $subscribeConfig): bool
    {
        $deviceId = $subscribeConfig['device_id'];
        $channelId = $subscribeConfig['channel_id'];

        // 检查设备是否在线
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);
        if (!$device || $device['status'] !== 'online') {
            Log::channel('sip')->warning('设备离线，跳过订阅下发', [
                'device_id' => $deviceId
            ]);
            return false;
        }

        $gb28181Client = $this->getGb28181Client();
        $success = true;

        // 目录订阅
        if ($subscribeConfig['event_catalog']) {
            try {
                $gb28181Client->subscribeCatalog($deviceId, [
                    'expires' => $subscribeConfig['subscribe_expires']
                ]);
                Log::channel('sip')->info('目录订阅已下发', ['device_id' => $deviceId]);
            } catch (\Exception $e) {
                Log::channel('sip')->error('目录订阅失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
                $success = false;
            }
        } else {
            // 取消目录订阅
            try {
                $gb28181Client->unsubscribeCatalog($deviceId);
            } catch (\Exception $e) {
                Log::channel('sip')->warning('取消目录订阅失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 报警订阅
        if ($subscribeConfig['event_alarm']) {
            try {
                $gb28181Client->subscribeAlarm($deviceId, [
                    'expires' => $subscribeConfig['subscribe_expires'],
                    'start_priority' => $subscribeConfig['alarm_priority_min'],
                    'end_priority' => $subscribeConfig['alarm_priority_max']
                ]);
                Log::channel('sip')->info('报警订阅已下发', ['device_id' => $deviceId]);
            } catch (\Exception $e) {
                Log::channel('sip')->error('报警订阅失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
                $success = false;
            }
        } else {
            try {
                $gb28181Client->unsubscribeAlarm($deviceId);
            } catch (\Exception $e) {
                Log::channel('sip')->warning('取消报警订阅失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 移动位置订阅
        if ($subscribeConfig['event_mobile_position']) {
            try {
                $gb28181Client->subscribeMobilePosition($deviceId, [
                    'expires' => $subscribeConfig['subscribe_expires'],
                    'interval' => $subscribeConfig['mobile_interval_sec']
                ]);
                Log::channel('sip')->info('移动位置订阅已下发', ['device_id' => $deviceId]);
            } catch (\Exception $e) {
                Log::channel('sip')->error('移动位置订阅失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
                $success = false;
            }
        } else {
            try {
                $gb28181Client->unsubscribeMobilePosition($deviceId);
            } catch (\Exception $e) {
                Log::channel('sip')->warning('取消移动位置订阅失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 更新最后订阅时间
        if ($success) {
            $this->getDeviceSubscribeConfigDao()->update($subscribeConfig['id'], [
                'last_subscribed_at' => date('Y-m-d H:i:s'),
                'subscription_expires_at' => date('Y-m-d H:i:s', time() + $subscribeConfig['subscribe_expires'])
            ]);
        }

        return $success;
    }

    /**
     * 取消订阅
     */
    public function cancelSubscribe(int $configId): bool
    {
        $config = $this->getDeviceSubscribeConfigDao()->get($configId);
        if (!$config) {
            throw new \InvalidArgumentException('Config not found');
        }

        $deviceId = $config['device_id'];
        $gb28181Client = $this->getGb28181Client();

        // 取消各类订阅
        try {
            $gb28181Client->unsubscribeCatalog($deviceId);
        } catch (\Exception $e) {
            Log::channel('sip')->warning('取消目录订阅失败', ['error' => $e->getMessage()]);
        }

        try {
            $gb28181Client->unsubscribeAlarm($deviceId);
        } catch (\Exception $e) {
            Log::channel('sip')->warning('取消报警订阅失败', ['error' => $e->getMessage()]);
        }

        try {
            $gb28181Client->unsubscribeMobilePosition($deviceId);
        } catch (\Exception $e) {
            Log::channel('sip')->warning('取消移动位置订阅失败', ['error' => $e->getMessage()]);
        }

        // 更新状态
        $this->getDeviceSubscribeConfigDao()->update($configId, [
            'status' => 0,
            'subscription_expires_at' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return true;
    }

    /**
     * 续订即将过期的订阅
     */
    public function renewExpiringSubscriptions(string $expireTime): int
    {
        // 查询需要续订的配置
        $configs = $this->getDeviceSubscribeConfigDao()->findExpiringConfigs($expireTime);

        $renewed = 0;

        foreach ($configs as $config) {
            if ($config['auto_renew'] && $config['status'] == 1) {
                try {
                    $this->applySubscribeConfig($config);
                    $renewed++;
                } catch (\Exception $e) {
                    Log::channel('sip')->error('续订失败', [
                        'device_id' => $config['device_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $renewed;
    }

    /**
     * 查询订阅配置
     */
    public function getSubscribeConfig(string $deviceId, ?string $channelId = null): ?array
    {
        return $this->getDeviceSubscribeConfigDao()->getByDeviceAndChannel($deviceId, $channelId);
    }

    /**
     * 搜索订阅配置
     */
    public function searchSubscribeConfigs(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20): array
    {
        return $this->getDeviceSubscribeConfigDao()->search($conditions, $orderBys, $start, $limit);
    }

    /**
     * 统计订阅配置数量
     */
    public function countSubscribeConfigs(array $conditions): int
    {
        return $this->getDeviceSubscribeConfigDao()->count($conditions);
    }

    /**
     * 获取 DeviceSubscribeConfigDao
     */
    protected function getDeviceSubscribeConfigDao(): DeviceSubscribeConfigDao|DaoProxy
    {
        return $this->createDao('Subscribe:DeviceSubscribeConfigDao');
    }

    /**
     * 获取 DeviceService
     */
    protected function getDeviceService()
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * 获取 Gb28181Client
     */
    protected function getGb28181Client()
    {
        return $this->bfw['gb28181_client'];
    }

    /**
     * 验证订阅配置参数
     */
    private function validateSubscribeConfig(array $config): void
    {
        if (empty($config['event_catalog']) &&
            empty($config['event_alarm']) &&
            empty($config['event_mobile_position'])) {
            throw new \InvalidArgumentException('At least one subscription type must be selected');
        }

        if (isset($config['alarm_priority_min']) && isset($config['alarm_priority_max'])) {
            if ($config['alarm_priority_min'] > $config['alarm_priority_max']) {
                throw new \InvalidArgumentException('alarm_priority_min must be <= alarm_priority_max');
            }
        }
    }
}
