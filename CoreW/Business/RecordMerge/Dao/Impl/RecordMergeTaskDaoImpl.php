<?php

namespace CoreW\Business\RecordMerge\Dao\Impl;

use CoreW\Business\RecordMerge\Dao\RecordMergeTaskDao;
use CoreW\Dao\AdvancedDaoImpl;

class RecordMergeTaskDaoImpl extends AdvancedDaoImpl implements RecordMergeTaskDao
{
    protected $table = 'gv_record_merge_tasks';

    public function declares() : array
    {
        return [
            'serializes' => [
                'source_file_ids' => 'json',
            ],
            'orderbys'   => [
                'id',
                'created_at',
            ],
            'timestamps' => [
                'created_at',
                'updated_at',
            ],
            'datetime'   => [
                'created_at',
                'updated_at',
                'started_at',
                'finished_at',
            ],
            'conditions' => [
                'id = :id',
                'id IN (:ids)',
                'device_id = :device_id',
                'channel_id = :channel_id',
                'media_server_id = :media_server_id',
                'status = :status',
                'status IN (:statuses)',
                'start_time >= :start_time_gte',
                'start_time <= :start_time_lte',
                'end_time >= :end_time_gte',
                'end_time <= :end_time_lte',
            ],
        ];
    }

    public function claimTask(int $taskId) : int
    {
        $now = date('Y-m-d H:i:s');
        $sql = "UPDATE {$this->table()} SET status = 'merging', started_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'";
        return $this->db()->executeStatement($sql, [$now, $now, $taskId]);
    }

    public function findStuckTasks(int $timeoutMinutes = 30) : array
    {
        $sql = "SELECT * FROM {$this->table()} WHERE status = 'merging' AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)";
        return $this->db()->fetchAllAssociative($sql, [$timeoutMinutes]);
    }

    public function findExistingMerge(string $deviceId, string $channelId, int $startTime, int $endTime) : ?array
    {
        // 查找完全相同时间范围的非失败任务
        $sql = "SELECT * FROM {$this->table()}
                WHERE device_id = ? AND channel_id = ?
                AND start_time = ? AND end_time = ?
                AND status IN ('pending', 'merging', 'done')
                LIMIT 1";
        $row = $this->db()->fetchOne($sql, [$deviceId, $channelId, $startTime, $endTime]);
        return $row ? : null;
    }
}
