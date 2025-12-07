<?php

namespace app\process;

use Workerman\Crontab\Crontab;

class Task
{
    public function onWorkerStart(): void
    {
        echo "Task onWorkerStart\n";
        // 每5秒执行一次
        new Crontab('*/5 * * * * *', function(){
//            echo date('Y-m-d H:i:s')."\n";
        });
    }
}