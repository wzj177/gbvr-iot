<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface PlaybackRecordDao extends AdvancedDaoInterface
{
    public function getByDeviceAndChannel(string $deviceId, string $channelId);

    /**
     * 删除指定时间范围内重叠的录像记录
     * 删除条件：同设备、同通道、时间有交集
     */
    public function deleteOverlappingRecords(string $deviceId, string $channelId, int $minStartTime, int $maxEndTime) : int;

    /**
     * 删除指定时间范围内的所有录像记录（用于全量同步）
     */
    public function deleteRecordsInTimeRange(string $deviceId, ?string $channelId, int $startTime, int $endTime) : int;
}
