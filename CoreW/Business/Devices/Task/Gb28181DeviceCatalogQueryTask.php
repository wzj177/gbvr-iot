<?php

namespace CoreW\Business\Devices\Task;

use CoreW\Business\Common\BaseCrontabTask;
use CoreW\Business\Common\CrontabTaskInterface;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Core;
use support\Log;

class Gb28181DeviceCatalogQueryTask  extends BaseCrontabTask
{


    public function execute(): void
    {
        /**@var Gb28181Service $gb28181Service */
        $gb28181Service = $this->getBfw()->offsetGet('gb28181_service');
        try {
            $devices = $this->getDeviceService()->searchDevices([
                'subscribe_catalog' => 0, // 对于开启设备目录订阅的设备，系统不会主动查询目录
                'status' => DeviceStatusEnum::ONLINE->value,
            ], [], 0,PHP_INT_MAX, ['id', 'device_id', 'catalog_interval', 'last_catalog_at', 'last_heartbeat_at']);
            foreach ($devices as $device) {
                // 判断根据 now 和 last_catalog_at 的时间间隔是否满足 catalog_interval 来确定是否发送
                if ($device['catalog_interval'] == 0 || empty($device['catalog_interval']) || ($device['catalog_interval'] > 0 && $device['last_catalog_at'] + $device['catalog_interval'] > time())) {
                    continue;
                }

                $last_heartbeat_at_timestamp = $device['last_heartbeat_at'];
                if ($last_heartbeat_at_timestamp < time() - config('gb28181.check_offline_device_interval', 3600)) {
                    continue;
                }

                $gb28181Service->queryCatalog($device['device_id']);
                $gb28181Service->queryDeviceInfo($device['device_id']);
                // 更新设备目录查询时间
                $this->getDeviceService()->updateDevice($device['id'], [
                    'last_catalog_at' => time(),
                ]);
                echo "发送设备目录查询: " . $device['device_id'] . "\n";
            }

//            Log::channel('crontab')->info("定期发送设备目录查询完成");
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
        return $this->getBfw()->service('Devices:DeviceService');
    }
}