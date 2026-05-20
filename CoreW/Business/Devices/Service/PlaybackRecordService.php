<?php

namespace CoreW\Business\Devices\Service;

interface PlaybackRecordService
{
    public function getPlaybackRecordById($id);

    public function countPlaybackRecords(array $conditions);

    public function searchPlaybackRecords(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function createPlaybackRecord(array $fields);

    public function batchCreatePlaybackRecords(array $records) : int;

    /**
     * 保存录像记录（去重）
     * 先删除同设备同通道重叠时间段的旧记录，再插入新记录
     */
    public function savePlaybackRecords(array $records) : int;

    public function updatePlaybackRecord($id, array $fields);

    public function deletePlaybackRecordById($id);

    /**
     * 检查指定时间范围内是否有录像
     */
    public function hasRecordInTimeRange(string $deviceId, string $channelId, int $startTime, int $endTime) : bool;

    /**
     * 获取指定时间范围内的录像数量
     */
    public function countRecordsByTimeRange(string $deviceId, string $channelId, int $startTime, int $endTime) : int;

    /**
     * 删除指定时间范围内的所有录像记录（用于全量同步）
     */
    public function deleteRecordsInTimeRange(string $deviceId, int $startTime, int $endTime, ?string $channelId = null) : int;
}
