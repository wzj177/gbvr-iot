<?php

namespace app\queue\redis\fast;

use CoreW\Bfw;
use CoreW\Business\MediaServer\Enums\ServerStatusEnum;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Core;
use support\Log;
use Webman\RedisQueue\Consumer;

class SyncMediaServerStatusJob implements Consumer
{
    // 要消费的队列名
    public $queue = 'sync_media_server_status_job';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接
    public $connection = 'default';

    // 消费
    public function consume($data): bool
    {
        if (empty($data['mediaServerId'])) {
            Log::channel('queue')->warning('SyncMediaServerStatusJob: missing mediaServerId', ['data' => $data]);
            return false;
        }

        $mediaServerId = $data['mediaServerId'];

        try {
            /** @var MediaServerService $service */
            $service = $this->getBiz()->service('MediaServer:MediaServerService');

            $server = $service->getMediaServerById($mediaServerId);
            if (!$server) {
                Log::channel('queue')->warning('SyncMediaServerStatusJob: media server not found', ['id' => $mediaServerId]);
                return false;
            }

            $strategy = $service->getStrategy($server['type']);
            $isOnline = $strategy->isOnline($server);

            $service->updateMediaServer($mediaServerId, [
                'status' => $isOnline ? ServerStatusEnum::RUNNING->value : ServerStatusEnum::STOPPED->value,
                'last_sync_at' => date('Y-m-d H:i:s'),
            ]);

            Log::channel('queue')->info('SyncMediaServerStatusJob: completed', [
                'id' => $mediaServerId,
                'status' => $isOnline ? ServerStatusEnum::RUNNING->value : ServerStatusEnum::STOPPED->value
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::channel('queue')->error('SyncMediaServerStatusJob: failed', [
                'id' => $mediaServerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    protected function getBiz(): Bfw
    {
        return Core::instance();
    }
}
