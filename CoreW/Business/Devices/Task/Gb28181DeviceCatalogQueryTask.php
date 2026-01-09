<?php

namespace CoreW\Business\Devices\Task;

use CoreW\Business\Common\CrontabTaskInterface;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Core;
use support\Log;

class Gb28181DeviceCatalogQueryTask implements CrontabTaskInterface
{
    public function execute(): void
    {
        try {
            $devices = $this->getDeviceService()->searchDevices([
                'status' => DeviceStatusEnum::ONLINE->value,
            ], null, 0,PHP_INT_MAX, ['id', 'device_id', 'catalog_interval', 'last_catalog_at']);
            foreach ($devices as $device) {
                // 判断根据 now 和 last_catalog_at 的时间间隔是否满足 catalog_interval 来确定是否发送
                if ($device->catalog_interval > 0 && $device->last_catalog_at + $device->catalog_interval > time()) {
                    continue;
                }

                $this->getGb28181Service()->queryCatalog($device['device_id']);
                $this->getGb28181Service()->queryDeviceInfo($device['device_id']);
            }

            Log::channel('crontab')->info("定期发送设备目录查询完成");
        } catch (\Exception $e) {
            Log::channel('crontab')->error("定期发送设备目录查询异常: " . $e->getMessage());
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

    /**
     * 获取GB28181服务
     * @return Gb28181Service
     */
    private function getGb28181Service(): Gb28181Service
    {
        return Core::instance()->service('GB::Gb28181Service');
    }
}