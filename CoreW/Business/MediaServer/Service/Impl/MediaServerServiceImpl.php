<?php

namespace CoreW\Business\MediaServer\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Devices\Enums\MediaServerType;
use CoreW\Business\MediaServer\Dao\MediaServerDao;
use CoreW\Business\MediaServer\Enums\ServerStatusEnum;
use CoreW\Business\MediaServer\Strategy\MediaServerStrategyFactory;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\MediaServer\Strategy\MediaServerStrategyInterface;
use CoreW\Dao\DaoProxy;
use Ramsey\Uuid\Uuid;
use support\Log;
use Webman\RedisQueue\Client;

class MediaServerServiceImpl extends BaseService implements MediaServerService
{
    public function getMediaServerById($id)
    {
        return $this->getMediaServerDao()->get($id);
    }

    public function getMediaServerByServerId(string $serverId): ?array
    {
        $result = $this->getMediaServerDao()->getByServerId($serverId);

        return $result ?: null;
    }

    public function findServersByIds(array $ids)
    {
        return $this->getMediaServerDao()->findByIds($ids);
    }

    public function findServersByServerIds(array $serverIds)
    {
        return $this->getMediaServerDao()->findByServerIds($serverIds);
    }

    public function countMediaServers(array $conditions)
    {
        return $this->getMediaServerDao()->count($conditions);
    }

    public function searchMediaServers(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        return $this->getMediaServerDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    public function getSimpleList(): array
    {
        return $this->searchMediaServers([], [], 0, 100, ['id', 'server_id', 'name', 'type', 'status']);
    }

    public function createMediaServer(array $fields)
    {
        // 设置默认值
        $fields['server_id'] = $this->generateServerId();
        $fields['created_at'] = $fields['created_at'] ?? date('Y-m-d H:i:s');
        $fields['updated_at'] = $fields['updated_at'] ?? date('Y-m-d H:i:s');
        $fields['status'] = $fields['status'] ?? ServerStatusEnum::UNKNOWN->value;

        // 验证类型
        if (isset($fields['type']) && !MediaServerStrategyFactory::isSupported($fields['type'])) {
            throw new \InvalidArgumentException("Unsupported media server type: {$fields['type']}");
        }

        $row = $this->getMediaServerDao()->create($fields);

        // 创建后尝试异步同步状态
        if (!empty($row)) {
            try {
                Client::send('sync_media_server_status_job', [
                    'mediaServerId' => $row['id']
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to dispatch sync media server status job after creation', [
                    'instance' => $row,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $row;
    }

    protected function generateServerId(): string
    {
        while (true) {
            $serverId = Uuid::uuid4();
            $exist = $this->getMediaServerDao()->getByServerId($serverId);
            if (!$exist) {
                return $serverId;
            }
        }
    }

    public function updateMediaServer($id, array $fields)
    {
        // 如果更新 server_id，检查是否冲突
        if (isset($fields['server_id'])) {
            $existing = $this->getMediaServerByServerId($fields['server_id']);
            if ($existing && $existing['id'] != $id) {
                throw new \InvalidArgumentException("Server ID '{$fields['server_id']}' already exists");
            }
        }

        // 验证类型
        if (isset($fields['type']) && !MediaServerStrategyFactory::isSupported($fields['type'])) {
            throw new \InvalidArgumentException("Unsupported media server type: {$fields['type']}");
        }

        $fields['updated_at'] = $fields['updated_at'] ?? date('Y-m-d H:i:s');

        return $this->getMediaServerDao()->update($id, $fields);
    }

    public function deleteMediaServerById($id)
    {
        // TODO: 检查是否有关联的通道，如果有关联的通道，不允许删除

        return $this->getMediaServerDao()->delete($id);
    }

    public function getStats(int $id): array
    {
        $server = $this->getMediaServerById($id);

        if (!$server) {
            throw new \InvalidArgumentException("Media server not found: {$id}");
        }

        $strategy = $this->getStrategy($server['type']);
        $stats = $strategy->getStats($server);

        // 更新缓存的统计数据
        $this->updateMediaServer($id, [
            'status' => $stats['status'] ?? ServerStatusEnum::UNKNOWN->value,
            'cpu_usage' => $stats['cpu_usage'] ?? 0,
            'memory_usage' => $stats['memory_usage'] ?? 0,
            'stream_count' => $stats['stream_count'] ?? 0,
            'player_count' => $stats['total_connection_count'] ?? 0,
            'uptime' => $stats['uptime'] ?? 0,
            'last_sync_at' => date('Y-m-d H:i:s'),
        ]);

        return $stats;
    }

    public function getConfig(int $id): array
    {
        $server = $this->getMediaServerById($id);

        if (!$server) {
            throw new \InvalidArgumentException("Media server not found: {$id}");
        }

        $strategy = $this->getStrategy($server['type']);

        return $strategy->getConfig($server);
    }

    public function setConfig(int $id, array $config): bool
    {
        $server = $this->getMediaServerById($id);

        if (!$server) {
            throw new \InvalidArgumentException("Media server not found: {$id}");
        }

        $strategy = $this->getStrategy($server['type']);
        $result = $strategy->setConfig($server, $config);
        if ($result && $server['type'] === MediaServerType::ZLM->value) {
            // zlm更新密钥
            if ($server['secret'] !== $config['api']['secret']) {
                $this->updateMediaServer($id, [
                    'secret' => $config['api']['secret'],
                ]);
            }
        }

        return $result;
    }

    public function restart(int $id): bool
    {
        $server = $this->getMediaServerById($id);

        if (!$server) {
            throw new \InvalidArgumentException("Media server not found: {$id}");
        }

        $strategy = $this->getStrategy($server['type']);

        return $strategy->restart($server);
    }

    public function syncStatus(int $id): bool
    {
        $server = $this->getMediaServerById($id);

        if (!$server) {
            throw new \InvalidArgumentException("Media server not found: {$id}");
        }

        $strategy = $this->getStrategy($server['type']);
        $isOnline = $strategy->isOnline($server);

        $this->updateMediaServer($id, [
            'status' => $isOnline ? ServerStatusEnum::RUNNING->value : ServerStatusEnum::STOPPED->value,
            'last_sync_at' => date('Y-m-d H:i:s'),
        ]);

        return $isOnline;
    }

    public function getStrategy(string $type): MediaServerStrategyInterface
    {
        return MediaServerStrategyFactory::getStrategy($type);
    }

    /**
     * @return MediaServerDao|DaoProxy
     */
    protected function getMediaServerDao(): MediaServerDao | DaoProxy
    {
        return $this->createDao('MediaServer:MediaServerDao');
    }
}
