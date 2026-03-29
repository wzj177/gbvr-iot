<?php

namespace CoreW\Business\RecordFile\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface RecordFileDao extends AdvancedDaoInterface
{
    /**
     * 根据 stream_id 和 media_server_id 查找录像任务
     */
    public function getByStreamAndMediaServer(string $streamId, string $mediaServerId): ?array;

    /**
     * 创建录像文件记录
     */
    public function createRecordFile(array $data): array;

    public function getRecordFileDateListByPlanId(int $planId): array;

    public function getRecordFileSizeByPlanId(int $planId): int;

    public function softDeleteByPlanIdAndDate(int $planId, string $recordDate): int;

    /**
     * 查询录像文件（带设备和通道信息）
     *
     * @param array $conditions 查询条件
     * @param array $orderBys 排序
     * @param int $start 起始位置
     * @param int $limit 每页数量
     * @return array
     */
    public function searchWithDeviceInfo(array $conditions, array $orderBys, int $start, int $limit): array;
}
