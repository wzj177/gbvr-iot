<?php

namespace app\process;

use CoreW\Business\Devices\Task\Gb28181DeviceCatalogQueryTask;
use CoreW\Business\Record\Task\PlaybackRecordTask;
use Workerman\Crontab\Crontab;

class ScheduleTaskProcess
{
    public function onWorkerStart(): void
    {
        // 每分钟执行一次 - 报警录像任务
//        new Crontab('* */1 * * * *', function () {
//            AlarmRecordTaskExecutor::run();
//        });

        // 每5秒执行一次 - 回放下载录像任务
        new Crontab('*/5 * * * * *', function () {
            PlaybackRecordTask::run();
        });

        // 每5分钟执行一次 - 获取国标设备目录任务
        new Crontab('* */5 * * * *', function () {
            Gb28181DeviceCatalogQueryTask::run();
        });
    }
}