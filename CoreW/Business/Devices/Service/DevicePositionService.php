<?php

namespace CoreW\Business\Devices\Service;

interface DevicePositionService
{
    /**
     * 保存设备位置信息
     *
     * @param array $positionData 位置数据
     * @return array 创建的位置记录
     */
    public function savePosition(array $positionData): array;

    /**
     * 获取设备最新位置
     *
     * @param string $deviceId 设备ID
     * @return array|null 最新位置记录
     */
    public function getLatestPosition(string $deviceId): ?array;

    /**
     * 查询设备位置历史
     *
     * @param array $conditions 查询条件
     * @param array $orderBys 排序
     * @param int $start 起始位置
     * @param int $limit 每页数量
     * @return array
     */
    public function searchPositions(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20): array;

    /**
     * 统计位置记录数量
     *
     * @param array $conditions 查询条件
     * @return int
     */
    public function countPositions(array $conditions): int;

    /**
     * 获取设备轨迹（指定时间范围）
     *
     * @param string $deviceId 设备ID
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @return array 轨迹点列表
     */
    public function getDeviceTrack(string $deviceId, string $startTime, string $endTime): array;

    /**
     * 清理历史位置数据（超过指定天数）
     *
     * @param int $days 保留天数
     * @return int 删除记录数
     */
    public function cleanupOldPositions(int $days = 30): int;

    /**
     * 获取多个设备的最新位置（电子地图点位）
     *
     * @param array $deviceIds 设备ID数组（为空则查询所有有位置的设备）
     * @param int|null $partnerId 合作方ID（可选）
     * @return array 设备位置列表，key为device_id
     */
    public function getMultiDeviceLatestPositions(array $deviceIds = [], ?int $partnerId = null): array;

    /**
     * 获取多个设备的历史轨迹（电子地图轨迹）
     *
     * @param array $deviceIds 设备ID数组
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @param int|null $partnerId 合作方ID（可选）
     * @return array 设备轨迹列表，key为device_id，value为轨迹点数组
     */
    public function getMultiDeviceTracks(array $deviceIds, string $startTime, string $endTime, ?int $partnerId = null): array;
}
