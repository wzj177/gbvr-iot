<?php

namespace CoreW\Business\Devices\Task;

use CoreW\Business\Common\CrontabTaskInterface;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Core;
use support\Log;

class Gb28181DeviceStatusCheckTask implements CrontabTaskInterface
{
    public function execute(): void
    {
        try {
            $devices = $this->getDeviceService()->searchDevices([], null, 0,PHP_INT_MAX, ['id', 'device_id', 'status']);
            foreach ($devices as $device) {
                $last_heartbeat_at_timestamp = strtotime($device->last_heartbeat_at);
                if ($last_heartbeat_at_timestamp < time() - config('gb28181.check_offline_device_interval', 3600)) {
                    $this->getDeviceService()->updateDeviceStatus($device['id'], DeviceStatusEnum::UNREGISTERED->value);
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
        return Core::instance()->service('Device::DeviceService');
    }
}