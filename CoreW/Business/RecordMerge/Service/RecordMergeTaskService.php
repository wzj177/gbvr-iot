<?php

namespace CoreW\Business\RecordMerge\Service;

interface RecordMergeTaskService
{
    /**
     * 创建合并任务
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $startTime 开始时间戳
     * @param int $endTime 结束时间戳
     * @return array 创建的任务
     * @throws \CoreW\Business\RecordMerge\Exception\RecordMergeException
     */
    public function createMergeTask(string $deviceId, string $channelId, int $startTime, int $endTime) : array;

    /**
     * 搜索合并任务
     */
    public function searchMergeTasks(array $conditions, array $orderBys, int $start, int $limit) : array;

    /**
     * 统计合并任务
     */
    public function countMergeTasks(array $conditions) : int;

    /**
     * 获取合并任务详情
     */
    public function getMergeTask(int $id) : ?array;

    /**
     * 删除合并任务（仅 done/failed 状态）
     */
    public function deleteMergeTask(int $id) : bool;

    /**
     * 取消合并任务（仅 pending 状态）
     */
    public function cancelMergeTask(int $id) : bool;

    /**
     * 处理待执行的合并任务（Crontab 调用）
     */
    public function processPendingTasks() : int;

    /**
     * 重置卡住的任务（Crontab 调用）
     */
    public function resetStuckTasks() : int;
}
