<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface StreamSessionsDao extends AdvancedDaoInterface
{
    public function getByCallId(int $callId);
    public function getByStreamId(string $callId);
    public function getBySsrc(string $ssrc);

    public function deleteAllByExpireTime(int $expireTime): int|string;

    public function deleteByDeviceId(string $deviceId): int|string;
    
    /**
     * 获取冷却中的端口
     * 
     * @param int $coolingTime 冷却时间（秒），默认20秒
     * @return array 端口列表
     */
    public function getCoolingPorts(int $coolingTime = 20): array;
}