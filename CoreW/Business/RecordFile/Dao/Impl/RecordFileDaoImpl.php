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
                'record_date >= :record_date_GE',
                'record_date <= :record_date_LE',
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
     * 根据ID列表查询录像文件
     */
    public function findByIds(array $ids) : array
    {
        if (empty($ids)) {
            return [];
        }
        return $this->findInField('id', $ids);
    }
}
