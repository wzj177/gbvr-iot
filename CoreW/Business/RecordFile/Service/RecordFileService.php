<?php

namespace CoreW\Business\RecordFile\Service;

interface RecordFileService
{
    /**
     * 从 ZLM hook 创建录像文件记录
     *
     * @param array $hookData ZLM on_record_mp4 hook 数据
     * @param string $mediaServerId 媒体服务器 ID
     * @return array|null 创建的记录，失败返回 null
     */
    public function createFromHook(array $hookData, string $mediaServerId) : ?array;

    /**
     * 查询录像文件
     *
     * @param array $conditions 查询条件
     * @param array $orderBys 排序
     * @param int $start 起始位置
     * @param int $limit 每页数量
     * @return array
     */
    public function searchRecordFiles(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20) : array;

    /**
     * 统计录像文件数量
     *
     * @param array $conditions 查询条件
     * @return int
     */
    public function countRecordFiles(array $conditions) : int;

    /**
     * 获取指定计划的录像日期列表
     */
    public function getRecordFileDateListByPlanId(int $planId) : array;

    /**
     * 获取指定计划的录像文件总大小（Byte）
     */
    public function getRecordFileSizeByPlanId(int $planId) : int;

    /**
     * 软删除指定计划+日期的录像文件
     */
    public function softDeleteByPlanIdAndDate(int $planId, string $recordDate) : int;

    /**
     * 查询录像文件（带设备和通道信息）
     *
     * @param array $conditions 查询条件
     * @param array $orderBys 排序
     * @param int $start 起始位置
     * @param int $limit 每页数量
     * @return array
     */
    public function searchRecordFilesWithDeviceInfo(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20) : array;
}
