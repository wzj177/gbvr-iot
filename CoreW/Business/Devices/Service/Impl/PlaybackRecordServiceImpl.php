<?php

namespace CoreW\Business\Devices\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Devices\Service\PlaybackRecordService;
use CoreW\Business\Devices\Dao\PlaybackRecordDao;
use CoreW\Dao\DaoProxy;

class PlaybackRecordServiceImpl extends BaseService implements PlaybackRecordService
{
    public function getPlaybackRecordById($id)
    {
        return $this->getPlaybackRecordDao()->get($id);
    }

    public function countPlaybackRecords(array $conditions)
    {
        return $this->getPlaybackRecordDao()->count($conditions);
    }

    public function searchPlaybackRecords(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        return $this->getPlaybackRecordDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    public function createPlaybackRecord(array $fields)
    {
        $record = array_merge([
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $fields);

        return $this->getPlaybackRecordDao()->create($record);
    }

    public function batchCreatePlaybackRecords(array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($records as &$record) {
            $record['created_at'] = $now;
            $record['updated_at'] = $now;
        }

        return $this->getPlaybackRecordDao()->batchCreate($records);
    }

    public function savePlaybackRecords(array $records): int
    {
        if (empty($records)) {
            return 0;
        }

        $this->beginTransaction();
        try {
            // 按设备和通道分组
            $grouped = [];
            foreach ($records as $record) {
                $key = $record['device_id'] . '_' . $record['channel_id'];
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'device_id' => $record['device_id'],
                        'channel_id' => $record['channel_id'],
                        'min_start' => PHP_INT_MAX,
                        'max_end' => 0,
                    ];
                }
                $grouped[$key]['min_start'] = min($grouped[$key]['min_start'], $record['start_time']);
                $grouped[$key]['max_end'] = max($grouped[$key]['max_end'], $record['end_time']);
            }

            // 删除重叠时间段的旧记录
            foreach ($grouped as $item) {
                $this->getPlaybackRecordDao()->deleteOverlappingRecords(
                    $item['device_id'],
                    $item['channel_id'],
                    $item['min_start'],
                    $item['max_end']
                );
            }

            // 批量插入新记录
            $count = $this->batchCreatePlaybackRecords($records);

            $this->commit();
            return $count;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function updatePlaybackRecord($id, array $fields)
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        return $this->getPlaybackRecordDao()->update($id, $fields);
    }

    public function deletePlaybackRecordById($id)
    {
        return $this->getPlaybackRecordDao()->delete($id);
    }

    /**
     * 检查指定时间范围内是否有录像
     */
    public function hasRecordInTimeRange(string $deviceId, string $channelId, int $startTime, int $endTime): bool
    {
        $count = $this->countPlaybackRecords([
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'start_time_LTE' => $endTime,
            'end_time_GTE' => $startTime,
        ]);

        return $count > 0;
    }

    /**
     * 获取指定时间范围内的录像数量
     */
    public function countRecordsByTimeRange(string $deviceId, string $channelId, int $startTime, int $endTime): int
    {
        return $this->countPlaybackRecords([
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'start_time_LTE' => $endTime,
            'end_time_GTE' => $startTime,
        ]);
    }

    /**
     * @return PlaybackRecordDao|DaoProxy
     */
    protected function getPlaybackRecordDao()
    {
        return $this->createDao('Devices:PlaybackRecordDao');
    }

}
