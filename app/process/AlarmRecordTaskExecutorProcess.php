<?php

namespace app\process;

use CoreW\Business\Record\Task\AlarmRecordTaskExecutor;
use Workerman\Crontab\Crontab;

/**
 * 报警录像任务执行器进程
 *
 * 每分钟执行一次，处理待执行的录像任务并停止超时的录像
 */
class AlarmRecordTaskExecutorProcess
{
    public function onWorkerStart(): void
    {
        // 每分钟执行一次
        new Crontab('* * * * *', function () {
            AlarmRecordTaskExecutor::run();
        });
    }
}
