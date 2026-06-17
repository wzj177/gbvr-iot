<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface StreamSessionsDao extends AdvancedDaoInterface
{
    public function getByCallId(int $callId);

    public function getByStreamId(string $streamId);

    public function getActiveByStreamIdAndType(string $streamId, string $type);


    public function getBySsrc(string $ssrc);

    public function deleteAllByExpireTime(int $expireTime) : int|string;

    public function deleteByDeviceId(string $deviceId) : int|string;

    public function deleteByStreamId(string $streamId) : int|string;

    /**
     * 获取冷却中的端口
     *
     * @param int $coolingTime 冷却时间（秒），默认20秒
     * @return array 端口列表
     */
    public function getCoolingPorts(int $coolingTime = 20) : array;

    /**
     * CAS（Compare-And-Set）递减 viewer_count
     *
     * 用于乐观锁：仅当 viewer_count > 1 时才递减
     * 返回影响行数：0 = 已是1或不存在（需要真正关闭），>0 = 递减成功
     *
     * @param string $streamId 流ID
     * @param string $type 会话类型（live/playback/talk/download）
     * @return int 影响行数
     */
    public function casDecrementViewerCount(string $streamId, string $type) : int;
}