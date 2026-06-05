<?php

namespace app\queue\redis\fast;

use CoreW\Bfw;
use CoreW\Business\MediaServer\Enums\ServerStatusEnum;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Core;
use Webman\RedisQueue\Consumer;

class SyncMediaServerStatusJob implements Consumer
{
    // 要消费的队列名
    public $queue = 'sync_media_server_status_job';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接
    public $connection = 'default';

    // 消费
    public function consume($data) : bool
    {
        if (empty($data['mediaServerId'])) {
            $this->getLogService()->warning(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_SYNC_MEDIA_SERVER_STATUS, '缺少媒体服务器ID', ['data' => $data]);
            return false;
        }

        $mediaServerId = $data['mediaServerId'];

        try {
            /** @var MediaServerService $service */
            $service = $this->getBiz()->service('MediaServer:MediaServerService');

            $server = $service->getMediaServerById($mediaServerId);
            if (!$server) {
                $this->getLogService()->warning(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_SYNC_MEDIA_SERVER_STATUS, '媒体服务器不存在', ['id' => $mediaServerId]);
                return false;
            }

            $strategy = $service->getStrategy($server['type']);
            $isOnline = $strategy->isOnline($server);

            $service->updateMediaServer($mediaServerId, [
                'status'       => $isOnline ? ServerStatusEnum::RUNNING->value : ServerStatusEnum::STOPPED->value,
                'last_sync_at' => date('Y-m-d H:i:s'),
            ]);

            if ($isOnline) {
                $strategy->setConfig($server, [
                    'general' => ['mediaServerId' => $server['server_id']],
                    'http'    => ['sslport' => $server['https_port']],
                ]);
            }

//            $this->getLogService()->info(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_SYNC_MEDIA_SERVER_STATUS, '同步媒体服务器状态完成', [
//                'id'     => $mediaServerId,
//                'status' => $isOnline ? ServerStatusEnum::RUNNING->value : ServerStatusEnum::STOPPED->value,
//            ]);

            return true;
        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_MEDIA_SERVER, LogEnum::ACTION_SYNC_MEDIA_SERVER_STATUS, '同步媒体服务器状态失败', [
                'id'    => $mediaServerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    protected function getBiz() : Bfw
    {
        return Core::instance();
    }

    protected function getLogService() : \CoreW\Business\SystemLog\Service\SystemLogService
    {
        return $this->getBiz()->service('SystemLog:SystemLogService');
    }
}
