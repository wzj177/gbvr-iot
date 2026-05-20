<?php

namespace app\process;

use CoreW\Business\Devices\Task\Gb28181DeviceStatusCheckTask;
use Workerman\Crontab\Crontab;

class Gb28181DeviceStatusCheckTaskProcess
{
    public function onWorkerStart() : void
    {
        // 每5s刷新一次：根据心跳时间来更新设备状态
        new Crontab('*/5 * * * * *', function () {
            Gb28181DeviceStatusCheckTask::run();
        });
    }
}