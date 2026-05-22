<?php

namespace CoreW\Business\RecordFile\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\RecordFile\Dao\RecordFileDao;

class RecordFileDaoImpl extends AdvancedDaoImpl implements RecordFileDao
{

    protected $table = 'gv_record_file';

    public function declares() : array
    {
        return [
            'serializes' => [],
            'orderbys'   => [
                'id',
                'created_at',
                'start_time'
            ],
            'timestamps' => [
                'created_at',
                'updated_at',
            ],
            'datetime'   => [
                'created_at',
                'updated_at',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'stream_id = :stream_id',
                'media_server_id = :media_server_id',
                'source_type = :source_type',
                'source_id = :source_id',
                'source_id IN (:source_id_IN)',
                'device_id = :device_id',
                'channel_id = :channel_id',
                'plan_id = :plan_id',
                'record_date = :record_date',
                'delete_at IS NULL',
            ],
        ];
    }

    public function getByStreamAndMediaServer(string $streamId, string $mediaServerId) : ?array
    {
        $sql = "SELECT * FROM {$this->table()}
                WHERE stream_id = ?
                AND media_server_id = ?
                LIMIT 1";

        return $this->db()->fetchOne($sql, [$streamId, $mediaServerId]) ? : null;
    }

    public function createRecordFile(array $data) : array
    {
        $this->db()->insert($this->table(), $data);
        $id = (int)$this->db()->lastInsertId();

        return $this->get($id);
    }

    /**
     * 获取指定计划的录像日期列表（去重+升序）
     */
    public function getRecordFileDateListByPlanId(int $planId) : array
    {
        $sql = "SELECT DISTINCT record_date FROM {$this->table()} WHERE plan_id = ? AND delete_at IS NULL ORDER BY record_date ASC";
        $rows = $this->db()->fetchAllAssociative($sql, [$planId]);
        return array_column($rows, 'record_date');
    }

    /**
     * 获取指定计划的录像文件总大小
     */
    public function getRecordFileSizeByPlanId(int $planId) : int
    {
        $sql = "SELECT COALESCE(SUM(file_size), 0) AS total FROM {$this->table()} WHERE plan_id = ? AND delete_at IS NULL";
        return (int)$this->db()->fetchOne($sql, [$planId]);
    }

    /**
     * 批量软删除指定计划+日期的文件
     */
    public function softDeleteByPlanIdAndDate(int $planId, string $recordDate) : int
    {
        $sql = "UPDATE {$this->table()} SET delete_at = NOW() WHERE plan_id = ? AND record_date = ? AND delete_at IS NULL";
        return $this->db()->executeStatement($sql, [$planId, $recordDate]);
    }

    /**
     * 查询录像文件（带设备和通道信息）
     */
    public function searchWithDeviceInfo(array $conditions, array $orderBys, int $start, int $limit) : array
    {
        // 构建 WHERE 条件
        $whereClauses = ['rf.delete_at IS NULL'];
        $params = [];

        if (!empty($conditions['id'])) {
            $whereClauses[] = 'rf.id = ?';
            $params[] = $conditions['id'];
        }
        if (!empty($conditions['device_id'])) {
            $whereClauses[] = 'rf.device_id = ?';
            $params[] = $conditions['device_id'];
        }
        if (!empty($conditions['channel_id'])) {
            $whereClauses[] = 'rf.channel_id = ?';
            $params[] = $conditions['channel_id'];
        }
        if (!empty($conditions['source_type'])) {
            $whereClauses[] = 'rf.source_type = ?';
            $params[] = $conditions['source_type'];
        }
        if (isset($conditions['plan_id'])) {
            $whereClauses[] = 'rf.plan_id = ?';
            $params[] = $conditions['plan_id'];
        }
        if (!empty($conditions['stream_id'])) {
            $whereClauses[] = 'rf.stream_id = ?';
            $params[] = $conditions['stream_id'];
        }
        if (!empty($conditions['media_server_id'])) {
            $whereClauses[] = 'rf.media_server_id = ?';
            $params[] = $conditions['media_server_id'];
        }

        $whereClause = implode(' AND ', $whereClauses);

        // 构建 ORDER BY
        $orderByClause = 'rf.id DESC';
        if (!empty($orderBys)) {
            $orderParts = [];
            foreach ($orderBys as $field => $direction) {
                $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
                $orderParts[] = "rf.{$field} {$direction}";
            }
            $orderByClause = implode(', ', $orderParts);
        }

        $sql = "SELECT rf.*,
                       d.device_name AS device_name,
                       dc.channel_name AS channel_name_latest,
                       COALESCE(ps.plan_total_size, 0) AS plan_total_size,
                       COALESCE(ps.plan_recorded_days, 0) AS plan_recorded_days
                FROM {$this->table()} rf
                LEFT JOIN gv_devices d ON rf.device_id = d.device_id
                LEFT JOIN gv_device_channels dc ON rf.device_id = dc.device_id AND rf.channel_id = dc.channel_id
                LEFT JOIN (
                    SELECT plan_id,
                           SUM(file_size) AS plan_total_size,
                           COUNT(DISTINCT record_date) AS plan_recorded_days
                    FROM {$this->table()}
                    WHERE delete_at IS NULL AND plan_id > 0
                    GROUP BY plan_id
                ) ps ON rf.plan_id = ps.plan_id
                WHERE {$whereClause}
                ORDER BY {$orderByClause}
                LIMIT {$start}, {$limit}";

        return $this->db()->fetchAllAssociative($sql, $params);
    }
}
