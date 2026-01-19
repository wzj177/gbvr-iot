<?php

namespace app\process;

use CoreW\Business\MediaServer\Tasks\RefreshMediaServerStatusTask;
use Workerman\Crontab\Crontab;

class RefreshMediaServerStatusTaskProcess
{
    public function onWorkerStart(): void
    {
        // 每5分钟执行一次：刷新媒体服务器状态
        new Crontab('* */5 * * * *', function () {
            RefreshMediaServerStatusTask::run();
        });
    }
}