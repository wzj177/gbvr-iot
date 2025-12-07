<?php

namespace CoreW\Business\Devices\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Devices\Dao\StreamSessionsDao;

class StreamSessionsDaoImpl extends AdvancedDaoImpl implements StreamSessionsDao
{

    protected $table = 'gv_stream_sessions';

    public function deleteAllByExpireTime(int $expireTime): int|string
    {
        $builder = $this->getQueryBuilder([
            'updated_at' => $expireTime,
            'status'     => ['inviting', 'ringing'],
        ]);

        return $this->delete($builder);
    }

    public function getByCallId(int $callId)
    {
        return $this->getByFields([
            'call_id' => $callId
        ]);
    }

    public function getByStreamId(string $callId)
    {
        return $this->getByFields([
            'stream_id' => $callId
        ]);
    }


    /**
     * 删除设备下的所有频道
     * @param string $deviceId
     * @return int|string
     * @throws \Doctrine\DBAL\Exception
     */
    public function deleteByDeviceId(string $deviceId): int|string
    {
        return  $this->db()->delete($this->table(), ['device_id' => $deviceId]);
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
            ],
        ];
    }
}
