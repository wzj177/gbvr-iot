<?php

namespace CoreW\Business\StreamProxy\Dao\Impl;

use CoreW\Business\StreamProxy\Dao\StreamProxyLogDao;
use CoreW\Dao\AdvancedDaoImpl;

/**
 * 流代理日志 DAO 实现
 */
class StreamProxyLogDaoImpl extends AdvancedDaoImpl implements StreamProxyLogDao
{
    protected $table = 'gv_stream_proxy_logs';

    public function declares() : array
    {
        return [
            'serializes' => [
                'details' => 'json',
            ],
            'orderbys'   => [
                'id',
                'created_at',
            ],
            'datetime'   => [
                'created_at',
            ],
            'conditions' => [
                'id = :id',
                'id IN (:ids)',
                'proxy_id = :proxy_id',
                'proxy_id IN (:proxy_ids)',
                'event_type = :event_type',
                'event_type IN (:event_types)',
                'level = :level',
                'level IN (:levels)',
                'created_at >= :created_at_GE',
                'created_at <= :created_at_LE',
                'message LIKE :message_LIKE',
            ],
        ];
    }

    public function findByProxyId(string $proxyId, array $orderBys = [], int $start = 0, int $limit = 100) : array
    {
        $orderBys = $orderBys ? : ['created_at' => 'DESC'];
        return $this->search(['proxy_id' => $proxyId], $orderBys, $start, $limit);
    }

    public function countByProxyId(string $proxyId) : int
    {
        return $this->count(['proxy_id' => $proxyId]);
    }

    public function deleteBeforeDate(string $date) : int
    {
        $sql = "DELETE FROM {$this->table} WHERE created_at < ?";
        return $this->db()->executeUpdate($sql, [$date]);
    }

    public function deleteByProxyId(string $proxyId) : int
    {
        return $this->db()->delete($this->table, ['proxy_id' => $proxyId]);
    }
}
