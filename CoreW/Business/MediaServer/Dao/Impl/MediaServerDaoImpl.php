<?php

namespace CoreW\Business\MediaServer\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\MediaServer\Dao\MediaServerDao;

class MediaServerDaoImpl extends AdvancedDaoImpl implements MediaServerDao
{

    protected $table = 'gv_media_servers';

    public function getByServerId(string $serverId)
    {
        // TODO: Implement getByServerId() method.
        return $this->getByFields(['server_id' => $serverId]);
    }

    public function findByIds(array $ids)
    {
        return $this->findInField('id', $ids);
    }

    public function findByServerIds(array $serverIds)
    {
        return $this->findInField('server_id', $serverIds);
    }

    public function declares(): array
    {
        return [
            'serializes' => [
            ],
            'orderbys' => [
                'id',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'server_id = :serverId',
                'server_id IN (:serverIds)',
                'server_id NOT IN (:noServerIds)',
                'status = :status',
                'created_time > :createdTime_GT',
                'created_time < :createdTime_LT',
                'created_time >= :createdTime_GTE',
                'created_time <= :createdTime_LTE',
                'type = :type',
                '(name LIKE :keywords OR host LIKE :keywords OR secret LIKE :keywords)',
            ],
        ];
    }
}
