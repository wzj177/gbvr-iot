<?php

namespace CoreW\Business\Record\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface RecordTaskDao extends AdvancedDaoInterface
{
    /**
     * 查找待执行的录像任务
     */
    public function findPendingTasks(int $limit = 100): array;

    /**
     * 查找需要停止的录像任务（超时或达到结束时间）
     */
    public function findRecordingTasksToStop(): array;

    /**
     * 根据stream_id查找任务
     */
    public function getByStreamId(string $streamId): ?array;
}
