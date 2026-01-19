<?php

namespace app\process;

use CoreW\Business\Devices\Task\CleanupRtpPortAndClearStreamSessionTask;
use CoreW\Business\Devices\Task\Gb28181DeviceCatalogQueryTask;
use CoreW\Business\Devices\Task\Gb28181DeviceStatusCheckTask;
use CoreW\Business\Devices\Task\Gb28181SubscriptionTask;
use CoreW\Business\MediaServer\Tasks\RefreshMediaServerStatusTask;
use Workerman\Crontab\Crontab;

class Gb28181DeviceCatalogQueryTaskProcess
{
    public function onWorkerStart(): void
    {
        // 每s执行一次: 获取设备目录
        new Crontab('*/1 * * * * *', function () {
            Gb28181DeviceCatalogQueryTask::run();
        });








    }
}
