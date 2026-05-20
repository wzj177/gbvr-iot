<?php

namespace CoreW\Business\RecordMerge\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface RecordMergeTaskDao extends AdvancedDaoInterface
{
    /**
     * CAS 原子抢占：pending → merging
     * @return int affected rows (1=抢到, 0=已被抢)
     */
    public function claimTask(int $taskId) : int;

    /**
     * 查询卡住的任务（merging 超过指定分钟）
     * @return array
     */
    public function findStuckTasks(int $timeoutMinutes = 30) : array;

    /**
     * 检查指定设备和时间范围是否已有合并任务（pending/merging/done）
     * @return array|null
     */
    public function findExistingMerge(string $deviceId, string $channelId, int $startTime, int $endTime) : ?array;
}
