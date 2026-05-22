<?php

namespace CoreW\Business\Devices\Dao\Impl;

use CoreW\Business\Devices\Dao\DevicePositionDao;
use CoreW\Dao\AdvancedDaoImpl;

class DevicePositionDaoImpl extends AdvancedDaoImpl implements DevicePositionDao
{
    protected $table = 'gv_device_positions';

    public function declares() : array
    {
        return [
            'serializes' => [],
            'orderbys'   => [
                'id',
                'time',
                'recv_time',
            ],
            'timestamps' => [],
            'datetime'   => [],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'device_id = :device_id',
                'device_id IN (:device_ids)',
                'partner_id = :partner_id',
                'time >= :time_GTE',
                'time <= :time_LTE',
                'time BETWEEN :time_start AND :time_end',
                'recv_time >= :recv_time_GTE',
                'recv_time <= :recv_time_LTE',
            ],
        ];
    }

    public function deleteOldPositions(int $cutoffTime) : int
    {
        $sql = "DELETE FROM {$this->table()} WHERE time < ?";
        return $this->db()->executeStatement($sql, [$cutoffTime]);
    }

    public function getLatestPositionsByDevices(array $deviceIds = [], ?int $partnerId = null) : array
    {
        // 使用子查询找到每个设备的最新时间，然后关联查询完整记录
        $whereConditions = [];
        $params = [];

        if (!empty($deviceIds)) {
            $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
            $whereConditions[] = "p.device_id IN ($placeholders)";
            $params = array_merge($params, $deviceIds);
        }

        if ($partnerId !== null) {
            $whereConditions[] = "p.partner_id = ?";
            $params[] = $partnerId;
        }

        $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

        $sql = "SELECT p.*
                FROM {$this->table()} p
                INNER JOIN (
                    SELECT device_id, MAX(time) as max_time
                    FROM {$this->table()}
                    " . ($partnerId !== null ? "WHERE partner_id = ?" : "") . "
                    GROUP BY device_id
                ) latest ON p.device_id = latest.device_id AND p.time = latest.max_time
                {$whereClause}
                ORDER BY p.device_id";

        // 如果有partner_id过滤，需要在子查询参数中也加入
        if ($partnerId !== null) {
            $subQueryParams = [$partnerId];
            $finalParams = array_merge($subQueryParams, $params);
        } else {
            $finalParams = $params;
        }

        $positions = $this->db()->fetchAllAssociative($sql, $finalParams);

        // 转换为以device_id为key的数组
        $result = [];
        foreach ($positions as $position) {
            $result[$position['device_id']] = $position;
        }

        return $result;
    }

    public function getTracksByDevices(array $deviceIds, int $startTime, int $endTime, ?int $partnerId = null) : array
    {
        if (empty($deviceIds)) {
            return [];
        }

        $whereConditions = [];
        $params = [];

        $placeholders = implode(',', array_fill(0, count($deviceIds), '?'));
        $whereConditions[] = "device_id IN ($placeholders)";
        $params = array_merge($params, $deviceIds);

        $whereConditions[] = "time >= ?";
        $params[] = $startTime;

        $whereConditions[] = "time <= ?";
        $params[] = $endTime;

        if ($partnerId !== null) {
            $whereConditions[] = "partner_id = ?";
            $params[] = $partnerId;
        }

        $whereClause = implode(' AND ', $whereConditions);

        $sql = "SELECT *
                FROM {$this->table()}
                WHERE {$whereClause}
                ORDER BY device_id ASC, time ASC";

        $positions = $this->db()->fetchAllAssociative($sql, $params);

        // 按device_id分组
        $result = [];
        foreach ($positions as $position) {
            $deviceId = $position['device_id'];
            if (!isset($result[$deviceId])) {
                $result[$deviceId] = [];
            }
            $result[$deviceId][] = $position;
        }

        return $result;
    }
}
