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

    public function getByStreamId(string $streamId)
    {
        return $this->getByFields([
            'stream_id' => $streamId
        ]);
    }

    public function getActiveByStreamIdAndType(string $streamId, string $type): array|false
    {
        $sql = "SELECT * FROM {$this->table()} WHERE stream_id=? AND `type` =? AND `viewer_count` >= 1 AND `status` IN ('inviting', 'active') order by id desc limit 1;";

        return $this->db()->fetchAssociative($sql, [$streamId, $type]);
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
     * 根据流ID删除会话
     * @param string $streamId
     * @return int|string
     * @throws \Doctrine\DBAL\Exception
     */
    public function deleteByStreamId(string $streamId): int|string
    {
        return  $this->db()->delete($this->table(), ['stream_id' => $streamId]);
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
        
        $sql = "SELECT DISTINCT rtp_port FROM {$this->table()} 
                WHERE rtp_port IS NOT NULL 
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
                'stream_id = :stream_id',
                'type = :type',
                'ssrc = :ssrc',
                'call_id = :call_id',
                'status = :status',
                'media_server_id = :media_server_id',
                'device_id = :device_id',
                'channel_id = :channel_id',
                'viewer_count = :viewer_count',
                'viewer_count >= :viewer_count_GE',
                'viewer_count <= :viewer_count_LE',
                'viewer_count > :viewer_count_GT',
                'viewer_count < :viewer_count_LT',
            ],
        ];
    }
}