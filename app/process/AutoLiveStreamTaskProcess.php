<?php

namespace app\process;

use CoreW\Business\Devices\Task\AutoLiveStreamTask;
use Workerman\Timer;

class AutoLiveStreamTaskProcess
{
    public function onWorkerStart(): void
    {
        $interval = (int) env('AUTO_LIVE_INTERVAL') ?: 10;

        Timer::add($interval, function () {
            AutoLiveStreamTask::run();
        });
    }
}
