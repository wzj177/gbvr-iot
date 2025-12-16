<?php

namespace app\process;

use Workerman\Crontab\Crontab;

class Task
{
    public function onWorkerStart(): void
    {
        echo "Task onWorkerStart\n";
        // 每1个小时执行一次: 更新设备目录
        new Crontab('*/5 * * * * *', function(){
//            echo date('Y-m-d H:i:s')."\n";
        });

        // 每30s执行一次：查询设备状态
    }
}