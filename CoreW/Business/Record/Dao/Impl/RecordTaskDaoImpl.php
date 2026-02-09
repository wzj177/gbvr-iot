<?php

namespace CoreW\Business\Record\Dao\Impl;

use CoreW\Business\Record\Dao\RecordTaskDao;
use CoreW\Dao\AdvancedDaoImpl;

class RecordTaskDaoImpl extends AdvancedDaoImpl implements RecordTaskDao
{
    protected $table = 'gv_record_task';

    public function declares(): array
    {
        return [
            'serializes' => [],
            'orderbys' => [
                'id',
                'created_at',
                'start_time',
                'end_time',
            ],
            'timestamps' => [
                'created_at',
                'updated_at',
            ],
            'datetime' => [
                'created_at',
                'updated_at',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'task_type = :task_type',
                'task_type IN (:task_types)',
                'device_id = :device_id',
                'device_id IN (:device_ids)',
                'channel_id = :channel_id',
                'channel_id IN (:channel_ids)',
                'stream_id = :stream_id',
                'ssrc = :ssrc',
                'status = :status',
                'status IN (:statuses)',
                'media_server_id = :media_server_id',
                'start_time > :start_time_after',
                'start_time >= :start_time_gte',
                'start_time < :start_time_before',
                'start_time <= :start_time_lte',
                'end_time > :end_time_after',
                'end_time >= :end_time_gte',
                'end_time < :end_time_before',
                'end_time <= :end_time_lte',
                'invite_ok_time > :invite_ok_time_after',
                'invite_ok_time >= :invite_ok_time_gte',
                'last_rtp_time > :last_rtp_time_after',
                'last_rtp_time >= :last_rtp_time_gte',
            ],
        ];
    }

    public function findPendingTasks(int $limit = 100): array
    {
        $now = time();
        $sql = "SELECT * FROM {$this->table()}
                WHERE status = 'pending'
                AND start_time <= ?
                ORDER BY created_at ASC
                LIMIT {$limit}";

        return $this->db()->fetchAllAssociative($sql, [$now]);
    }

    public function findRecordingTasksToStop(): array
    {
        $now = time();
        $sql = "SELECT * FROM {$this->table()}
                WHERE status = 'recording'
                AND end_time <= ?
                ORDER BY end_time ASC";

        return $this->db()->fetchAllAssociative($sql, [$now]);
    }

    public function  getByStreamId(string $streamId): ?array
    {
        return $this->getByFields(['stream_id' => $streamId]);
    }

    public function  getDoneByStreamId(string $streamId): ?array
    {
        return $this->getByFields(['stream_id' => $streamId, 'status' => 'done']);
    }

    public function getBySsrc(string $ssrc): ?array
    {
        return $this->getByFields(['ssrc' => $ssrc]);
    }

    public function getValidityTaskByStreamId(string $streamId): ?array
    {
        $task_type = 'playback_download';
        $now = time();
        // 未开始录制的任务超时时间：20秒（国标业务从 INVITE 到推流很快）
        $notStartedTimeout = 20;
        $timeoutTimestamp = $now - $notStartedTimeout;
        // 最近完成的任务时间窗口：10分钟
        $recentCompletedWindow = 600;
        $recentCompletedThreshold = $now - $recentCompletedWindow;

        $sql = "SELECT * FROM {$this->table()}
                WHERE task_type = '{$task_type}'
                AND stream_id = ?
                AND (
                    -- 未开始录制的任务（创建时间在20秒内）
                    (record_start_time = 0 AND UNIX_TIMESTAMP(created_at) > ?)
                    OR
                    -- 正在录制的任务（录制开始时间 + 预计时长 > 当前时间，给5秒缓冲）
                    (record_start_time > 0
                     AND record_start_time + record_duration + 5 > ?)
                    OR
                    -- 最近10分钟内完成的任务（防止重复下载）
                    (status = 'done'
                     AND record_end_time > 0
                     AND record_end_time > ?)
                )
                LIMIT 1";

        return $this->db()->fetchAssoc($sql, [$streamId, $timeoutTimestamp, $now, $recentCompletedThreshold]) ?: null;
    }

    public function findWaitStreamTasksWithMediaServer(): array
    {
        $task_type = 'playback_download';
        // 只查询最近1小时内创建的任务，避免扫描旧数据
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        $sql = "SELECT t.*,
                       ms.id as media_server_db_id,
                       ms.server_id,
                       ms.type,
                       ms.host,
                       ms.port,
                       ms.secret,
                       ms.record_path,
                       ms.stream_ip,
                       ms.status as media_server_status
                FROM {$this->table()} t
                LEFT JOIN gv_media_servers ms ON t.media_server_id = ms.server_id
                WHERE t.status = 'wait_stream'
                AND t.task_type = '{$task_type}'
                AND t.created_at >= '{$oneHourAgo}'
                ORDER BY t.created_at DESC";

        return $this->db()->fetchAllAssociative($sql);
    }

    public function findRecordingTasksWithMediaServer(): array
    {
        $task_type = 'playback_download';
        // 只查询最近1小时内创建的任务，避免扫描旧数据
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        $sql = "SELECT t.*,
                       ms.id as media_server_db_id,
                       ms.server_id,
                       ms.type,
                       ms.host,
                       ms.port,
                       ms.secret,
                       ms.record_path,
                       ms.stream_ip,
                       ms.status as media_server_status
                FROM {$this->table()} t
                LEFT JOIN gv_media_servers ms ON t.media_server_id = ms.server_id
                WHERE t.status = 'recording'
                AND t.task_type = '{$task_type}'
                AND t.created_at >= '{$oneHourAgo}'
                ORDER BY t.created_at DESC";

        return $this->db()->fetchAllAssociative($sql);
    }

    public function updateRecordStartTimeByStreamId(string $streamId, string $mediaServerId, int $time): int
    {
        $sql = "UPDATE {$this->table()}
                SET record_start_time = IF(record_start_time = 0, ?, record_start_time)
                WHERE stream_id = ?
                AND media_server_id = ?";
        return $this->db()->executeStatement($sql, [$time, $streamId, $mediaServerId]);
    }

    public function updateRecordEndTimeByStreamId(string $streamId, string $mediaServerId, int $time): int
    {
        $sql = "UPDATE {$this->table()}
                SET record_end_time = IF(record_end_time = 0, ?, record_end_time)
                WHERE stream_id = ?
                AND media_server_id = ?";
        return $this->db()->executeStatement($sql, [$time, $streamId, $mediaServerId]);
    }

    public function updateLastRtpTime(int $taskId, int $time): int
    {
        $sql = "UPDATE {$this->table()}
                SET last_rtp_time = ?
                WHERE id = ?";
        return $this->db()->executeStatement($sql, [$time, $taskId]);
    }

    public function findFinalizingTasks(): array
    {
        $task_type = 'playback_download';
        // 只查询最近1小时内创建的任务
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        $sql = "SELECT t.*,
                       ms.id as media_server_db_id,
                       ms.server_id,
                       ms.type,
                       ms.host,
                       ms.port,
                       ms.secret,
                       ms.record_path,
                       ms.stream_ip,
                       ms.status as media_server_status
                FROM {$this->table()} t
                LEFT JOIN gv_media_servers ms ON t.media_server_id = ms.server_id
                WHERE t.status = 'finalizing'
                AND t.task_type = '{$task_type}'
                AND t.created_at >= '{$oneHourAgo}'
                ORDER BY t.created_at ASC";

        return $this->db()->fetchAllAssociative($sql);
    }

    public function deleteOtherTasksByStreamId(string $streamId, int $excludeTaskId): int
    {
        $sql = "DELETE FROM {$this->table()}
                WHERE stream_id = ?
                AND id != ?";

        return $this->db()->executeStatement($sql, [$streamId, $excludeTaskId]);
    }
}
