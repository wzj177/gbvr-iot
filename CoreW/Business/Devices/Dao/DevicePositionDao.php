<?php

namespace CoreW\Business\Devices\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface DevicePositionDao extends AdvancedDaoInterface
{
    /**
     * 删除旧的位置记录
     *
     * @param int $cutoffTime 截止时间戳（早于此时间的记录将被删除）
     * @return int 删除的记录数
     */
    public function deleteOldPositions(int $cutoffTime): int;

    /**
     * 获取多个设备的最新位置
     *
     * @param array $deviceIds 设备ID数组（为空则查询所有）
     * @param int|null $partnerId 合作方ID（可选）
     * @return array 设备位置列表，key为device_id
     */
    public function getLatestPositionsByDevices(array $deviceIds = [], ?int $partnerId = null): array;

    /**
     * 获取多个设备的历史轨迹
     *
     * @param array $deviceIds 设备ID数组
     * @param int $startTime 开始时间戳
     * @param int $endTime 结束时间戳
     * @param int|null $partnerId 合作方ID（可选）
     * @return array 设备轨迹列表，key为device_id，value为轨迹点数组
     */
    public function getTracksByDevices(array $deviceIds, int $startTime, int $endTime, ?int $partnerId = null): array;
}
