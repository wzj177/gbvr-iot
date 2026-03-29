<?php

namespace CoreW\Business\Devices\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Devices\Dao\DevicePositionDao;
use CoreW\Business\Devices\Service\DevicePositionService;
use CoreW\Dao\DaoProxy;
use support\utils\ArrayToolkit;

class DevicePositionServiceImpl extends BaseService implements DevicePositionService
{
    public function savePosition(array $positionData): array
    {
        // 字段过滤和验证
        $fields = ArrayToolkit::parts($positionData, [
            'device_id', 'cmd_type', 'time', 'longitude', 'latitude',
            'speed', 'direction', 'altitude', 'recv_time', 'raw_data', 'partner_id',
        ]);

        // 转换time字段为时间戳（如果是字符串）
        if (isset($fields['time']) && is_string($fields['time'])) {
            $fields['time'] = strtotime($fields['time']);
        }
        if (isset($fields['recv_time']) && is_string($fields['recv_time'])) {
            $fields['recv_time'] = strtotime($fields['recv_time']);
        }

        // 设置默认值
        $fields['longitude'] = (float)($fields['longitude'] ?? 0);
        $fields['latitude'] = (float)($fields['latitude'] ?? 0);
        $fields['speed'] = (float)($fields['speed'] ?? 0);
        $fields['direction'] = (int)($fields['direction'] ?? 0);
        $fields['altitude'] = (float)($fields['altitude'] ?? 0);
        $fields['partner_id'] = (int)($fields['partner_id'] ?? 0);
        $fields['time'] = $fields['time'] ?? time();
        $fields['recv_time'] = $fields['recv_time'] ?? time();

        // 创建记录
        return $this->getDevicePositionDao()->create($fields);
    }

    public function getLatestPosition(string $deviceId): ?array
    {
        $conditions = ['device_id' => $deviceId];
        $orderBys = ['time' => 'DESC'];

        $positions = $this->getDevicePositionDao()->search($conditions, $orderBys, 0, 1);

        return $positions[0] ?? null;
    }

    public function searchPositions(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20): array
    {
        return $this->getDevicePositionDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countPositions(array $conditions): int
    {
        return $this->getDevicePositionDao()->count($conditions);
    }

    public function getDeviceTrack(string $deviceId, string $startTime, string $endTime): array
    {
        $startTimestamp = strtotime($startTime);
        $endTimestamp = strtotime($endTime);

        $conditions = [
            'device_id' => $deviceId,
            'time_GTE' => $startTimestamp,
            'time_LTE' => $endTimestamp,
        ];

        return $this->getDevicePositionDao()->search($conditions, ['time' => 'ASC'], 0, PHP_INT_MAX);
    }

    public function cleanupOldPositions(int $days = 30): int
    {
        $cutoffTime = time() - ($days * 86400);
        return $this->getDevicePositionDao()->deleteOldPositions($cutoffTime);
    }

    public function getMultiDeviceLatestPositions(array $deviceIds = [], ?int $partnerId = null): array
    {
        return $this->getDevicePositionDao()->getLatestPositionsByDevices($deviceIds, $partnerId);
    }

    public function getMultiDeviceTracks(array $deviceIds, string $startTime, string $endTime, ?int $partnerId = null): array
    {
        if (empty($deviceIds)) {
            return [];
        }

        $startTimestamp = strtotime($startTime);
        $endTimestamp = strtotime($endTime);

        return $this->getDevicePositionDao()->getTracksByDevices($deviceIds, $startTimestamp, $endTimestamp, $partnerId);
    }

    /**
     * @return DevicePositionDao|DaoProxy
     */
    protected function getDevicePositionDao(): DevicePositionDao|DaoProxy
    {
        return $this->createDao('Devices:DevicePositionDao');
    }
}
