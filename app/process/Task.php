<?php

namespace app\process;

use CoreW\Business\Devices\Task\Gb28181DeviceCatalogQueryTask;
use CoreW\Business\Devices\Task\Gb28181DeviceStatusCheckTask;
use CoreW\Business\Devices\Task\Gb28181SubscriptionTask;
use CoreW\Business\MediaServer\Tasks\RefreshMediaServerStatusTask;
use CoreW\Core;
use support\Log;
use Webman\RedisQueue\Client;
use Workerman\Crontab\Crontab;

class Task
{
    public function onWorkerStart(): void
    {
        echo "Task onWorkerStart\n";
        // 每s执行一次: 获取设备目录
        new Crontab('*/1 * * * * *', function(){
            $task = new Gb28181DeviceCatalogQueryTask();
            $task->execute();
        });

        // 每小时刷新一次订阅（expires - 5分钟）
        new Crontab('0 0 */1 * * *', function(){

            // TODO: 待实现
            $task = new Gb28181SubscriptionTask();
            $task->execute();
        });

        // 每30s执行一次：刷新媒体服务器状态
        new Crontab('*/30 * * * * *', function(){
            $task = new RefreshMediaServerStatusTask();
            $task->execute();
        });

        // 每5s刷新一次：根据心跳时间来更新设备状态
        new Crontab('*/5 * * * * *', function(){
            $task = new Gb28181DeviceStatusCheckTask();
            $task->execute();
        });

    }
}
