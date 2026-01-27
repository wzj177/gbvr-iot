<?php

namespace CoreW\Business\RecordFile\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\RecordFile\Dao\RecordFileDao;

class RecordFileDaoImpl extends AdvancedDaoImpl implements RecordFileDao
{

    protected $table = 'gv_record_file';

    public function declares(): array
    {
        return [
            'serializes' => [],
            'orderbys' => [
                'id',
                'created_at',
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
                'stream_id = :stream_id',
                'media_server_id = :media_server_id',
                'source_type = :source_type',
                'source_id = :source_id',
                'device_id = :device_id',
                'channel_id = :channel_id',
            ],
        ];
    }

    public function getByStreamAndMediaServer(string $streamId, string $mediaServerId): ?array
    {
        $sql = "SELECT * FROM {$this->table()}
                WHERE stream_id = ?
                AND media_server_id = ?
                LIMIT 1";

        return $this->db()->fetchOne($sql, [$streamId, $mediaServerId]) ?: null;
    }

    public function createRecordFile(array $data): array
    {
        $this->db()->insert($this->table(), $data);
        $id = (int) $this->db()->lastInsertId();

        return $this->get($id);
    }
}
