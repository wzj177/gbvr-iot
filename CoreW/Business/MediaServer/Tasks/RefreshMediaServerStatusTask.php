<?php

namespace CoreW\Business\MediaServer\Tasks;

use CoreW\Business\Common\CrontabTaskInterface;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Core;
use support\Log;
use Webman\RedisQueue\Client;

class RefreshMediaServerStatusTask implements CrontabTaskInterface
{
    public function execute(): void
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

                    Log::channel('crontab')->info('Media server status sync job dispatched', [
                        'server_id' => $server['server_id'],
                        'name' => $server['name'],
                        'status' => $server['status'],
                    ]);
                } catch (\Exception $e) {
                    Log::channel('crontab')->error('Failed to dispatch media server status sync job', [
                        'server_id' => $server['server_id'],
                        'name' => $server['name'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::channel('crontab')->error('Refresh media server status task failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}