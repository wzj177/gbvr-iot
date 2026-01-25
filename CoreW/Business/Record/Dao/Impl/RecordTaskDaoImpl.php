<?php

namespace CoreW\Business\Record\Dao\Impl;

use CoreW\Business\Record\Dao\RecordTaskDao;
use CoreW\Business\Record\Enums\RecordTaskTypeEnum;
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
                'start_time',
                'end_time',
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
            ],
        ];
    }

    public function findPendingTasks(int $limit = 100): array
    {
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT * FROM {$this->table()}
                WHERE status = 'pending'
                AND start_time <= ?
                ORDER BY created_at ASC
                LIMIT {$limit}";

        return $this->db()->fetchAll($sql, [$now]);
    }

    public function findRecordingTasksToStop(): array
    {
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT * FROM {$this->table()}
                WHERE status = 'recording'
                AND end_time <= ?
                ORDER BY end_time ASC";

        return $this->db()->fetchAll($sql, [$now]);
    }

    public function getByStreamId(string $streamId): ?array
    {
        return $this->getByFields(['stream_id' => $streamId]);
    }

    public function getBySsrc(string $ssrc): ?array
    {
        return $this->getByFields(['ssrc' => $ssrc]);
    }

    public function findWaitStreamTasks(int $limit = 100): array
    {
        $task_type = RecordTaskTypeEnum::PLAYBACK_DOWNLOAD->value;
        $sql = "SELECT * FROM {$this->table()}
                WHERE status = 'wait_stream'
                AND task_type = '{$task_type}'
                ORDER BY created_at ASC
                LIMIT {$limit}";

        return $this->db()->fetchAll($sql);
    }

    public function findRecordingTasks(int $limit = 100): array
    {
        $task_type = RecordTaskTypeEnum::PLAYBACK_DOWNLOAD->value;
        $sql = "SELECT * FROM {$this->table()}
                WHERE status = 'recording'
                AND task_type = '{$task_type}'
                ORDER BY created_at ASC
                LIMIT {$limit}";

        return $this->db()->fetchAll($sql);
    }
}
