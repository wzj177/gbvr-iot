<?php

namespace CoreW\Business\StreamProxy\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\StreamProxy\Dao\StreamProxyDao;

class StreamProxyDaoImpl extends AdvancedDaoImpl implements StreamProxyDao
{
    protected $table = 'gv_stream_proxies';

    public function getByProxyId(string $proxyId)
    {
        return $this->getByFields(['proxy_id' => $proxyId]);
    }

    public function findByIds(array $ids)
    {
        return $this->findInField('id', $ids);
    }

    public function findByProxyIds(array $proxyIds)
    {
        return $this->findInField('proxy_id', $proxyIds);
    }

    public function findByMediaServerId(string $mediaServerId)
    {
        return $this->findByFields(['media_server_id' => $mediaServerId]);
    }

    public function findByStatus(string $status)
    {
        return $this->findByFields(['status' => $status]);
    }

    public function findByType(string $type)
    {
        return $this->findByFields(['type' => $type]);
    }

    public function findByRecordPlanId(int $recordPlanId)
    {
        return $this->findByFields(['record_plan_id' => $recordPlanId]);
    }

    public function declares(): array
    {
        return [
            'serializes' => [
                'tags' => 'json',
            ],
            'orderbys' => [
                'id',
                'created_at',
                'updated_at',
                'started_at',
                'last_heartbeat_at',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id < :id_LT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'proxy_id = :proxyId',
                'proxy_id IN (:proxyIds)',
                'proxy_id NOT IN (:noProxyIds)',
                'name = :name',
                'name LIKE :nameLike',
                'type = :type',
                'type IN (:types)',
                'protocol = :protocol',
                'protocol IN (:protocols)',
                'status = :status',
                'status IN (:statuses)',
                'status NOT IN (:noStatuses)',
                'media_server_id = :mediaServerId',
                'media_server_id IN (:mediaServerIds)',
                'app = :app',
                'stream = :stream',
                'record_plan_id = :recordPlanId',
                'record_plan_id > :recordPlanId_GT',
                'record_plan_id IN (:recordPlanIds)',
                'record_status = :recordStatus',
                'enable_auto_reconnect = :enableAutoReconnect',
                'created_at > :createdAt_GT',
                'created_at < :createdAt_LT',
                'created_at >= :createdAt_GTE',
                'created_at <= :createdAt_LTE',
                'updated_at > :updatedAt_GT',
                'updated_at < :updatedAt_LT',
                'updated_at >= :updatedAt_GTE',
                'updated_at <= :updatedAt_LTE',
                'last_heartbeat_at > :lastHeartbeatAt_GT',
                'last_heartbeat_at < :lastHeartbeatAt_LT',
                'last_heartbeat_at >= :lastHeartbeatAt_GTE',
                'last_heartbeat_at <= :lastHeartbeatAt_LTE',
                'last_heartbeat_at IS NULL',
                '(name LIKE :keywords OR description LIKE :keywords OR source_url LIKE :keywords)',
            ],
            'timestamps' => [
                'created_at',
                'updated_at',
            ],
        ];
    }
}
