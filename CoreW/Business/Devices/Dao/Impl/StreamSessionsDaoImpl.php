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

    public function getBySsrc(string $ssrc)
    {
        return $this->getByFields([
            'ssrc' => $ssrc
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
    
    /**
     * 获取冷却中的端口
     * 
     * @param int $coolingTime 冷却时间（秒），默认20秒
     * @return array 端口列表
     */
    public function getCoolingPorts(int $coolingTime = 20): array
    {
        $coolingTimeAgo = date('Y-m-d H:i:s', time() - $coolingTime);
        
        $sql = "SELECT DISTINCT zlm_port FROM {$this->table()} 
                WHERE zlm_port IS NOT NULL 
                AND updated_at > ? 
                AND status IN ('stopped', 'error')";
                
        $stmt = $this->db()->prepare($sql);
        $result = $stmt->executeQuery([$coolingTimeAgo]);
        
        return $result->fetchAllAssociative();
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