<?php

namespace app\process;

use CoreW\Business\Devices\Task\Gb28181DeviceCatalogQueryTask;
use CoreW\Business\Devices\Task\Gb28181SubscriptionTask;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Core;
use support\Log;
use Webman\RedisQueue\Client;
use Workerman\Crontab\Crontab;

class Task
{
    public function onWorkerStart(): void
    {
        echo "Task onWorkerStart\n";
        // 每1个小时执行一次: 更新设备目录
        new Crontab('*/5 * * * * *', function(){
//            echo date('Y-m-d H:i:s')."\n";
            $task = new Gb28181DeviceCatalogQueryTask();
            $task->execute();
        });

        // 每小时刷新一次订阅（expires - 5分钟）
        new Crontab('0 0 * * * *', function(){
            $task = new Gb28181SubscriptionTask();
            $task->execute();
        });

        // 每30s执行一次：刷新媒体服务器状态
        new Crontab('*/30 * * * * *', function(){
            $this->refreshMediaServerStatus();
        });

    }

    /**
     * 刷新媒体服务器状态
     */
    private function refreshMediaServerStatus(): void
    {
        try {
            /** @var MediaServerService $mediaServerService */
            $mediaServerService = Core::instance()->service('MediaServer:MediaServerService');

            // 获取所有媒体服务器
            $servers = $mediaServerService->getSimpleList();

            foreach ($servers as $server) {
                try {
                    // 异步分发同步任务到队列
                    Client::send('sync_media_server_status_job', [
                        'mediaServerId' => $server['id']
                    ]);

                    // 避免干扰，延迟一下
                    usleep(100000); // 100ms

                    Log::channel('zlm')->info('Media server status sync job dispatched', [
                        'server_id' => $server['server_id'],
                        'name' => $server['name'],
                        'status' => $server['status'],
                    ]);
                } catch (\Exception $e) {
                    Log::channel('zlm')->error('Failed to dispatch media server status sync job', [
                        'server_id' => $server['server_id'],
                        'name' => $server['name'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Refresh media server status task failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
