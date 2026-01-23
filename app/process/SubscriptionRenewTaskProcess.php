<?php

namespace app\process;

use CoreW\Business\Subscribe\Task\SubscriptionRenewTask;
use Workerman\Crontab\Crontab;

/**
 * 订阅续期任务进程
 *
 * 每10分钟执行一次，续订即将过期的订阅
 */
class SubscriptionRenewTaskProcess
{
    public function onWorkerStart(): void
    {
        // 每10分钟执行一次
        new Crontab('*/10 * * * *', function () {
            SubscriptionRenewTask::run();
        });
    }
}
