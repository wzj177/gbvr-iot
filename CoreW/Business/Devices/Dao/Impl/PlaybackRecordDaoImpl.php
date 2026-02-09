<?php

namespace CoreW\Business\Devices\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Devices\Dao\PlaybackRecordDao;

class PlaybackRecordDaoImpl extends AdvancedDaoImpl implements PlaybackRecordDao
{

    protected $table = 'gv_device_playback_records';

    public function getByDeviceAndChannel(string $deviceId, string $channelId)
    {
        return $this->getByFields([
            'device_id' => $deviceId,
            'channel_id' => $channelId
        ]);
    }

    /**
     * 删除指定时间范围内重叠的录像记录
     * 删除条件：同设备、同通道、时间有交集
     */
    public function deleteOverlappingRecords(string $deviceId, string $channelId, int $minStartTime, int $maxEndTime): int
    {
        $sql = "DELETE FROM {$this->table()}
                WHERE device_id = ?
                AND channel_id = ?
                AND (
                    (start_time <= ? AND end_time >= ?)  -- 旧记录完全包含新时间段
                    OR (start_time >= ? AND end_time <= ?)  -- 新时间段完全包含旧记录
                    OR (start_time < ? AND end_time > ?)  -- 旧记录与新时间段部分重叠
                )";

        return $this->db()->executeStatement($sql, [
            $deviceId,
            $channelId,
            $minStartTime,
            $maxEndTime,
            $minStartTime,
            $maxEndTime,
            $maxEndTime,
            $minStartTime,
        ]);
    }

    /**
     * 删除指定时间范围内的所有录像记录（用于全量同步）
     */
    public function deleteRecordsInTimeRange(string $deviceId, ?string $channelId, int $startTime, int $endTime): int
    {
        $sql = "DELETE FROM {$this->table()}
                WHERE device_id = ?
                AND start_time >= ?
                AND end_time <= ?";

        $params = [$deviceId, $startTime, $endTime];

        if ($channelId) {
            $sql .= " AND channel_id = ?";
            $params[] = $channelId;
        }

        return $this->db()->executeStatement($sql, $params);
    }

    public function declares(): array
    {
        return [
            'serializes' => [
            ],
            'orderbys' => [
                'id',
                'start_time',
                'end_time',
            ],
            'timestamps' => [
            ],
            'datetime' => [
                'created_at',
                'updated_at',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'device_id = :device_id',
                'device_id IN (:device_ids)',
                'channel_id = :channel_id',
                'channel_id IN (:channel_ids)',
                'start_time >= :start_time_GTE',
                'start_time <= :start_time_LTE',
                'end_time >= :end_time_GTE',
                'end_time <= :end_time_LTE',
            ],
        ];
    }
}
