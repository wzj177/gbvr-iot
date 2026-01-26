<?php

namespace CoreW\Business\Devices\Task;

use CoreW\Business\Common\BaseCrontabTask;
use CoreW\Business\Common\CrontabTaskInterface;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Core;
use support\Log;

class Gb28181DeviceStatusCheckTask  extends BaseCrontabTask
{
    public function execute(): void
    {
        try {
            $devices = $this->getDeviceService()->searchDevices([], [], 0,PHP_INT_MAX, ['id', 'device_id', 'status', 'last_heartbeat_at']);
            foreach ($devices as $device) {
                $last_heartbeat_at_timestamp = (int)$device['last_heartbeat_at'];
//                echo "设备ID: {$device['device_id']} 最后心跳时间: {$device['last_heartbeat_at']} \n";
                if ($last_heartbeat_at_timestamp < time() - config('gb28181.check_offline_device_interval', 3600)) {
//                    echo "设备ID: {$device['device_id']} 离线 \n";
                    $this->getDeviceService()->updateDeviceStatus($device['device_id'], DeviceStatusEnum::UNREGISTERED->value);
                }
            }

            Log::channel('crontab')->info("定期检测设备状态完成", $devices);
        } catch (\Exception $e) {
            Log::channel('crontab')->error("定期检测设备状态异常: " . $e->getMessage());
        }
    }

    /**
     * 获取设备服务
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return Core::instance()->service('Devices:DeviceService');
    }
}