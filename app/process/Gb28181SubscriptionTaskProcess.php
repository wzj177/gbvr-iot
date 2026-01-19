<?php

namespace app\process;

use CoreW\Business\Devices\Task\Gb28181SubscriptionTask;
use Workerman\Crontab\Crontab;

class Gb28181SubscriptionTaskProcess
{
    public function onWorkerStart(): void
    {
        // 每小时刷新一次订阅（expires - 5分钟）
        new Crontab('0 0 */1 * * *', function () {
            // TODO: 待实现
            Gb28181SubscriptionTask::run();
        });
    }
}