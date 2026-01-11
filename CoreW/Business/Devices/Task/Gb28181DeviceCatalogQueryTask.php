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
    private Gb28181Service $gb28181Service;
    public function __construct()
    {
        $this->gb28181Service = new Gb28181Service(Core::instance());
    }

    public function execute(): void
    {
        try {
            $devices = $this->getDeviceService()->searchDevices([
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

                $this->gb28181Service->queryCatalog($device['device_id']);
                $this->gb28181Service->queryDeviceInfo($device['device_id']);
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
        return Core::instance()->service('Devices:DeviceService');
    }
}