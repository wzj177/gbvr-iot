<?php

namespace app\process;

use CoreW\Business\Common\BaseCrontabTask;
use CoreW\Business\Record\Task\AlarmRecordTaskExecutor;
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
            PlaybackRecordTaskProcess::run();
        });
    }
}