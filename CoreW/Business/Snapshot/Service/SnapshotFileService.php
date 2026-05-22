<?php

namespace CoreW\Business\Snapshot\Service;

interface SnapshotFileService
{
    /**
     * 报警快照抓拍
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $alarmEventId 报警事件ID
     * @param string $imageFormat 图片格式
     * @return array 快照记录
     */
    public function captureAlarmSnapshot(string $deviceId, string $channelId, int $alarmEventId, string $imageFormat = 'JPEG') : array;

    /**
     * 从活跃流抓拍
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $streamId 流ID
     * @param string $sourceType 来源类型
     * @param int|null $sourceId 来源ID
     * @param int $timeoutSec 超时时间（秒）
     * @return array|null 快照记录，失败返回null
     */
    public function captureFromStream(string $deviceId, string $channelId, string $streamId, string $sourceType = 'manual', ?int $sourceId = null, int $timeoutSec = 5) : ?array;

    /**
     * INVITE 后抓拍（用于实时视频抓拍）
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $sourceType 来源类型
     * @param int|null $sourceId 来源ID
     * @param int $timeoutSec 超时时间（秒）
     * @return array|null 快照记录，失败返回null
     */
    public function captureAfterInvite(string $deviceId, string $channelId, string $sourceType = 'manual', ?int $sourceId = null, int $timeoutSec = 5) : ?array;

    /**
     * 查询快照记录
     *
     * @param array $conditions 查询条件
     * @param array $orderBys 排序
     * @param int $start 起始位置
     * @param int $limit 每页数量
     * @return array
     */
    public function searchSnapshots(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20) : array;

    /**
     * 统计快照记录数量
     *
     * @param array $conditions 查询条件
     * @return int
     */
    public function countSnapshots(array $conditions) : int;

    /**
     * 根据ID查询快照
     *
     * @param int $id
     * @return array|null
     */
    public function getSnapshot(int $id) : ?array;
}
