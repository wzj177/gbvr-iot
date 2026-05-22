<?php

namespace app\process;

use CoreW\Business\Devices\Task\CleanupRtpPortAndClearStreamSessionTask;
use Workerman\Crontab\Crontab;

class CleanupRtpPortAndClearStreamSessionTaskProcess
{
    public function onWorkerStart() : void
    {
        // 每1分钟执行一次：cleanup rtp
        new Crontab('* */1 * * * *', function () {
            CleanupRtpPortAndClearStreamSessionTask::run();
        });
    }
}