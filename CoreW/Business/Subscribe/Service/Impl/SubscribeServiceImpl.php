<?php

namespace CoreW\Business\Subscribe\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\Subscribe\Service\SubscribeService;
use CoreW\Business\Subscribe\Dao\DeviceSubscribeConfigDao;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Dao\DaoProxy;

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
        ];

        if ($existing) {
            $this->getDeviceSubscribeConfigDao()->update($existing['id'], $data);
            $subscribeConfig = $this->getDeviceSubscribeConfigDao()->get($existing['id']);
        } else {
            $subscribeConfig = $this->getDeviceSubscribeConfigDao()->create($data);
        }

        // 如果启用，立即下发订阅
        if ($subscribeConfig['status'] == 1) {
            try {
                $this->applySubscribeConfig($subscribeConfig);
            } catch (\Exception $e) {
                $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_UPDATE_SUBSCRIBE, '下发订阅配置失败', [
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
                $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_BATCH_CREATE_SUBSCRIBE_CONFIGS, '批量创建订阅配置失败', [
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
            $this->getLogService()->warning(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_APPLY_SUBSCRIBE_CONFIG, '设备离线，跳过订阅下发', [
                'device_id' => $deviceId
            ]);
            return false;
        }

        $gb28181Service = $this->getGb28181Service();
        $success = true;
        $updateFields = [];

        // 目录订阅
        if ($subscribeConfig['event_catalog']) {
            try {
                // 如果已有 dialog_id，使用续订
                if (!empty($subscribeConfig['catalog_dialog_id'])) {
                    $result = $gb28181Service->refreshSubscribe(
                        $subscribeConfig['catalog_dialog_id'],
                        'Catalog',
                        $subscribeConfig['subscribe_expires']
                    );

                    if ($result['success']) {
                        $updateFields['subscription_expires_at'] = date('Y-m-d H:i:s', time() + $subscribeConfig['subscribe_expires']);
                        $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '目录订阅已续订', [
                            'device_id' => $deviceId,
                            'dialog_id' => $subscribeConfig['catalog_dialog_id']
                        ]);
                    } else {
                        $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '目录订阅续订失败，将重新订阅', [
                            'device_id' => $deviceId,
                            'error' => $result['error'] ?? 'Unknown error'
                        ]);
                        // 续订失败，尝试重新订阅
                        $this->subscribeCatalogNew($gb28181Service, $deviceId, $subscribeConfig, $updateFields, $success);
                    }
                } else {
                    // 首次订阅
                    $this->subscribeCatalogNew($gb28181Service, $deviceId, $subscribeConfig, $updateFields, $success);
                }
            } catch (\Exception $e) {
                $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_CATALOG_SUBSCRIBE, '目录订阅异常', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
                $success = false;
            }
        } else {
            // 取消目录订阅
            try {
                $gb28181Service->unsubscribeCatalog($deviceId);
                $updateFields['catalog_dialog_id'] = null;
            } catch (\Exception $e) {
                $this->getLogService()->warning(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_CANCEL_SUBSCRIBE, '取消目录订阅失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 报警订阅
        if ($subscribeConfig['event_alarm']) {
            try {
                // 如果已有 dialog_id，使用续订
                if (!empty($subscribeConfig['alarm_dialog_id'])) {
                    $result = $gb28181Service->refreshSubscribe(
                        $subscribeConfig['alarm_dialog_id'],
                        'Alarm',
                        $subscribeConfig['subscribe_expires']
                    );

                    if ($result['success']) {
                        $updateFields['subscription_expires_at'] = date('Y-m-d H:i:s', time() + $subscribeConfig['subscribe_expires']);
                        $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '报警订阅已续订', [
                            'device_id' => $deviceId,
                            'dialog_id' => $subscribeConfig['alarm_dialog_id']
                        ]);
                    } else {
                        $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '报警订阅续订失败，将重新订阅', [
                            'device_id' => $deviceId,
                            'error' => $result['error'] ?? 'Unknown error'
                        ]);
                        // 续订失败，尝试重新订阅
                        $this->subscribeAlarmNew($gb28181Service, $deviceId, $subscribeConfig, $updateFields, $success);
                    }
                } else {
                    // 首次订阅
                    $this->subscribeAlarmNew($gb28181Service, $deviceId, $subscribeConfig, $updateFields, $success);
                }
            } catch (\Exception $e) {
                $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_ALARM_SUBSCRIBE, '报警订阅异常', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
                $success = false;
            }
        } else {
            try {
                $gb28181Service->unsubscribeAlarm($deviceId);
                $updateFields['alarm_dialog_id'] = null;
            } catch (\Exception $e) {
                $this->getLogService()->warning(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_CANCEL_SUBSCRIBE, '取消报警订阅失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 移动位置订阅
        if ($subscribeConfig['event_mobile_position']) {
            try {
                // 如果已有 dialog_id，使用续订
                if (!empty($subscribeConfig['position_dialog_id'])) {
                    $result = $gb28181Service->refreshSubscribe(
                        $subscribeConfig['position_dialog_id'],
                        'MobilePosition',
                        $subscribeConfig['subscribe_expires']
                    );

                    if ($result['success']) {
                        $updateFields['subscription_expires_at'] = date('Y-m-d H:i:s', time() + $subscribeConfig['subscribe_expires']);
                        $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '移动位置订阅已续订', [
                            'device_id' => $deviceId,
                            'dialog_id' => $subscribeConfig['position_dialog_id']
                        ]);
                    } else {
                        $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '移动位置订阅续订失败，将重新订阅', [
                            'device_id' => $deviceId,
                            'error' => $result['error'] ?? 'Unknown error'
                        ]);
                        // 续订失败，尝试重新订阅
                        $this->subscribeMobilePositionNew($gb28181Service, $deviceId, $subscribeConfig, $updateFields, $success);
                    }
                } else {
                    // 首次订阅
                    $this->subscribeMobilePositionNew($gb28181Service, $deviceId, $subscribeConfig, $updateFields, $success);
                }
            } catch (\Exception $e) {
                $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_MOBILE_POSITION_SUBSCRIBE, '移动位置订阅异常', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
                $success = false;
            }
        } else {
            try {
                $gb28181Service->unsubscribeMobilePosition($deviceId);
                $updateFields['position_dialog_id'] = null;
            } catch (\Exception $e) {
                $this->getLogService()->warning(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_MOBILE_POSITION_UNSUBSCRIBE, '取消移动位置订阅失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 更新最后订阅时间和 dialog_id
        if ($success) {
            $updateFields['last_subscribed_at'] = date('Y-m-d H:i:s');
            $updateFields['subscription_expires_at'] = date('Y-m-d H:i:s', time() + $subscribeConfig['subscribe_expires']);
        }
        
        if (!empty($updateFields)) {
            $this->getDeviceSubscribeConfigDao()->update($subscribeConfig['id'], $updateFields);
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
        $gb28181Service = $this->getGb28181Service();

        // 取消各类订阅
        try {
            $gb28181Service->unsubscribeCatalog($deviceId);
        } catch (\Exception $e) {
            $this->getLogService()->warning(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_CANCEL_SUBSCRIBE, '取消目录订阅失败', ['error' => $e->getMessage()]);
        }

        try {
            $gb28181Service->unsubscribeAlarm($deviceId);
        } catch (\Exception $e) {
            $this->getLogService()->warning(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_CANCEL_SUBSCRIBE, '取消报警订阅失败', ['error' => $e->getMessage()]);
        }

        try {
            $gb28181Service->unsubscribeMobilePosition($deviceId);
        } catch (\Exception $e) {
            $this->getLogService()->warning(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_CANCEL_SUBSCRIBE, '取消移动位置订阅失败', ['error' => $e->getMessage()]);
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
        $gb28181Service = $this->getGb28181Service();

        foreach ($configs as $config) {
            if (!$config['auto_renew'] || $config['status'] != 1) {
                continue;
            }
            
            $deviceId = $config['device_id'];
            $expires = $config['subscribe_expires'];
            $hasDialogId = false;

            try {
                // 目录订阅续期
                if ($config['event_catalog'] && !empty($config['catalog_dialog_id'])) {
                    $result = $gb28181Service->refreshSubscribe(
                        $config['catalog_dialog_id'],
                        'Catalog',
                        $expires
                    );

                    if ($result['success']) {
                        $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '目录订阅续期成功', [
                            'device_id' => $deviceId,
                            'dialog_id' => $config['catalog_dialog_id']
                        ]);
                        $hasDialogId = true;
                    }
                }

                // 报警订阅续期
                if ($config['event_alarm'] && !empty($config['alarm_dialog_id'])) {
                    $result = $gb28181Service->refreshSubscribe(
                        $config['alarm_dialog_id'],
                        'Alarm',
                        $expires
                    );

                    if ($result['success']) {
                        $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '报警订阅续期成功', [
                            'device_id' => $deviceId,
                            'dialog_id' => $config['alarm_dialog_id']
                        ]);
                        $hasDialogId = true;
                    }
                }

                // 移动位置订阅续期
                if ($config['event_mobile_position'] && !empty($config['position_dialog_id'])) {
                    $result = $gb28181Service->refreshSubscribe(
                        $config['position_dialog_id'],
                        'MobilePosition',
                        $expires
                    );

                    if ($result['success']) {
                        $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '移动位置订阅续期成功', [
                            'device_id' => $deviceId,
                            'dialog_id' => $config['position_dialog_id']
                        ]);
                        $hasDialogId = true;
                    }
                }

                // 如果没有 dialog_id,则重新订阅
                if (!$hasDialogId) {
                    $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '没有dialog_id，重新执行订阅', [
                        'device_id' => $deviceId
                    ]);
                    $this->applySubscribeConfig($config);
                } else {
                    // 更新过期时间
                    $this->getDeviceSubscribeConfigDao()->update($config['id'], [
                        'subscription_expires_at' => date('Y-m-d H:i:s', time() + $expires)
                    ]);
                }

                $renewed++;

            } catch (\Exception $e) {
                $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_RENEW_SUBSCRIPTION, '续订失败', [
                    'device_id' => $deviceId,
                    'error' => $e->getMessage()
                ]);
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
     * 获取 Gb28181Service
     * @return Gb28181Service
     */
    protected function getGb28181Service(): Gb28181Service
    {
        return $this->bfw->offsetGet('gb28181_service');
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

    /**
     * 首次订阅或重新订阅目录
     */
    private function subscribeCatalogNew($gb28181Service, string $deviceId, array $subscribeConfig, array &$updateFields, bool &$success): void
    {
        $result = $gb28181Service->subscribeCatalog($deviceId, [
            'expires' => $subscribeConfig['subscribe_expires']
        ]);

        if ($result['success']) {
            // 异步处理，dialog_id 将由网关通过 hook 回调更新
            $updateFields['subscription_expires_at'] = date('Y-m-d H:i:s', time() + $subscribeConfig['subscribe_expires']);
            $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_CATALOG_SUBSCRIBE, '目录订阅命令已发送', [
                'device_id' => $deviceId,
                'pending' => $result['pending'] ?? true
            ]);
        } else {
            $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_CATALOG_SUBSCRIBE, '目录订阅失败', [
                'device_id' => $deviceId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            $success = false;
        }
    }

    /**
     * 首次订阅或重新订阅报警
     */
    private function subscribeAlarmNew($gb28181Service, string $deviceId, array $subscribeConfig, array &$updateFields, bool &$success): void
    {
        $result = $gb28181Service->subscribeAlarm($deviceId, [
            'expires' => $subscribeConfig['subscribe_expires']
        ]);

        if ($result['success']) {
            // 异步处理，dialog_id 将由网关通过 hook 回调更新
            $updateFields['subscription_expires_at'] = date('Y-m-d H:i:s', time() + $subscribeConfig['subscribe_expires']);
            $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_ALARM_SUBSCRIBE, '报警订阅命令已发送', [
                'device_id' => $deviceId,
                'pending' => $result['pending'] ?? true
            ]);
        } else {
            $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_ALARM_SUBSCRIBE, '报警订阅失败', [
                'device_id' => $deviceId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            $success = false;
        }
    }

    /**
     * 首次订阅或重新订阅移动位置
     */
    private function subscribeMobilePositionNew($gb28181Service, string $deviceId, array $subscribeConfig, array &$updateFields, bool &$success): void
    {
        $result = $gb28181Service->subscribeMobilePosition($deviceId, [
            'expires' => $subscribeConfig['subscribe_expires'],
            'interval' => $subscribeConfig['mobile_interval_sec']
        ]);

        if ($result['success']) {
            // 异步处理，dialog_id 将由网关通过 hook 回调更新
            $updateFields['subscription_expires_at'] = date('Y-m-d H:i:s', time() + $subscribeConfig['subscribe_expires']);
            $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_MOBILE_POSITION_SUBSCRIBE, '移动位置订阅命令已发送', [
                'device_id' => $deviceId,
                'pending' => $result['pending'] ?? true
            ]);
        } else {
            $this->getLogService()->error(LogEnum::MODULE_SUBSCRIBE, LogEnum::ACTION_MOBILE_POSITION_SUBSCRIBE, '移动位置订阅失败', [
                'device_id' => $deviceId,
                'error' => $result['error'] ?? 'Unknown error'
            ]);
            $success = false;
        }
    }

    /**
     * 更新订阅的 dialog_id
     * 当收到 SUBSCRIBE 200 OK 响应时调用
     */
    public function updateDialogId(string $deviceId, string $eventType, int $dialogId, int $subscriptionId, int $expires): bool
    {
        $config = $this->getDeviceSubscribeConfigDao()->getByDeviceAndChannel($deviceId, null);
        
        if (!$config) {
            $this->getLogService()->warning(LogEnum::MODULE_SUBSCRIBE, 'update_dialog_id', '订阅配置不存在', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
            ]);
            return false;
        }

        // 根据事件类型更新对应的 dialog_id 字段
        $updateData = [
            'subscription_expires_at' => date('Y-m-d H:i:s', time() + $expires),
            'last_subscribed_at' => date('Y-m-d H:i:s'),
        ];

        switch (strtolower($eventType)) {
            case 'catalog':
                $updateData['catalog_dialog_id'] = $dialogId;
                $updateData['catalog_subscription_id'] = $subscriptionId;
                break;
            case 'alarm':
                $updateData['alarm_dialog_id'] = $dialogId;
                $updateData['alarm_subscription_id'] = $subscriptionId;
                break;
            case 'mobileposition':
            case 'mobile_position':
                $updateData['position_dialog_id'] = $dialogId;
                $updateData['position_subscription_id'] = $subscriptionId;
                break;
            default:
                $this->getLogService()->warning(LogEnum::MODULE_SUBSCRIBE, 'update_dialog_id', '未知的事件类型', [
                    'device_id' => $deviceId,
                    'event_type' => $eventType,
                ]);
                return false;
        }

        $this->getDeviceSubscribeConfigDao()->update($config['id'], $updateData);

        $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, 'update_dialog_id', 'dialog_id 更新成功', [
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'dialog_id' => $dialogId,
            'subscription_id' => $subscriptionId,
            'expires' => $expires,
        ]);

        return true;
    }

    /**
     * 更新订阅过期时间（续订成功时调用）
     */
    public function updateSubscriptionExpires(string $deviceId, string $eventType, int $dialogId, int $expires): bool
    {
        $config = $this->getDeviceSubscribeConfigDao()->getByDeviceAndChannel($deviceId, null);
        
        if (!$config) {
            return false;
        }

        $updateData = [
            'subscription_expires_at' => date('Y-m-d H:i:s', time() + $expires),
            'last_subscribed_at' => date('Y-m-d H:i:s'),
        ];

        $this->getDeviceSubscribeConfigDao()->update($config['id'], $updateData);

        $this->getLogService()->info(LogEnum::MODULE_SUBSCRIBE, 'update_expires', '订阅过期时间更新成功', [
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'dialog_id' => $dialogId,
            'expires' => $expires,
        ]);

        return true;
    }

    /**
     * 标记订阅为已过期/失效（续订失败时调用）
     */
    public function markSubscriptionExpired(string $deviceId, string $eventType, int $dialogId): bool
    {
        $config = $this->getDeviceSubscribeConfigDao()->getByDeviceAndChannel($deviceId, null);
        
        if (!$config) {
            return false;
        }

        // 清除失效的 dialog_id
        $updateData = [];
        
        switch (strtolower($eventType)) {
            case 'catalog':
                $updateData['catalog_dialog_id'] = 0;
                $updateData['catalog_subscription_id'] = 0;
                break;
            case 'alarm':
                $updateData['alarm_dialog_id'] = 0;
                $updateData['alarm_subscription_id'] = 0;
                break;
            case 'mobileposition':
            case 'mobile_position':
                $updateData['position_dialog_id'] = 0;
                $updateData['position_subscription_id'] = 0;
                break;
            default:
                return false;
        }

        $this->getDeviceSubscribeConfigDao()->update($config['id'], $updateData);

        $this->getLogService()->warning(LogEnum::MODULE_SUBSCRIBE, 'mark_expired', '订阅已标记为失效', [
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'dialog_id' => $dialogId,
        ]);

        return true;
    }
}
