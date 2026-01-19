<?php

namespace CoreW\Business\Devices\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Devices\Dao\StreamSessionViewerDao;

class StreamSessionViewerDaoImpl extends AdvancedDaoImpl implements StreamSessionViewerDao
{

    protected $table = 'gv_stream_session_viewers';

    public function declares(): array
    {
        return [
            'serializes' => [
            ],
            'orderbys' => [
                'id',
            ],
            'timestamps' => [],
            'datetime' => [
                'created_at',
                'updated_at',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
            ],
        ];
    }
}
