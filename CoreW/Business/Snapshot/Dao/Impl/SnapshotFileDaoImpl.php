<?php

namespace CoreW\Business\Snapshot\Dao\Impl;

use CoreW\Business\Snapshot\Dao\SnapshotFileDao;
use CoreW\Dao\AdvancedDaoImpl;

class SnapshotFileDaoImpl extends AdvancedDaoImpl implements SnapshotFileDao
{
    protected $table = 'gv_snapshot_file';

    public function declares() : array
    {
        return [
            'serializes' => [
                'ai_meta' => 'json',
            ],
            'orderbys'   => [
                'id',
                'shot_time',
                'created_at',
            ],
            'timestamps' => [
                'created_at',
                'updated_at',
            ],
            'datetime'   => [
                'shot_time',
                'delete_at',
                'created_at',
                'updated_at',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'device_id = :device_id',
                'device_id IN (:device_ids)',
                'channel_id = :channel_id',
                'channel_id IN (:channel_ids)',
                'source_type = :source_type',
                'source_type IN (:source_types)',
                'source_id = :source_id',
                'media_server_id = :media_server_id',
                'stream_id = :stream_id',
                'shot_time > :shot_time_after',
                'shot_time >= :shot_time_gte',
                'shot_time < :shot_time_before',
                'shot_time <= :shot_time_lte',
                'index_status = :index_status',
                'delete_at IS NULL',
            ],
        ];
    }

    public function getBySource(string $sourceType, ?int $sourceId) : array
    {
        $conditions = [
            'source_type' => $sourceType,
        ];

        if ($sourceId !== null) {
            $conditions['source_id'] = $sourceId;
        }

        return $this->search($conditions, ['shot_time' => 'DESC'], 0, 100);
    }
}
