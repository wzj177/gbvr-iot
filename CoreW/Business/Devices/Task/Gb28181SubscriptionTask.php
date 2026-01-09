<?php

namespace CoreW\Business\Devices\Task;

use CoreW\Business\Common\CrontabTaskInterface;

class Gb28181SubscriptionTask  implements CrontabTaskInterface
{
    // 每小时刷新一次订阅（expires - 5分钟）
    public function execute(): void
    {

    }

    // 清理过期订阅
    public function cleanupExpiredSubscriptions(): void
    {

    }
}