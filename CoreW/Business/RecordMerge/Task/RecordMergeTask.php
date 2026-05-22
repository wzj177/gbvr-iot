<?php

namespace CoreW\Business\RecordMerge\Task;

use CoreW\Business\Common\BaseCrontabTask;
use CoreW\Business\RecordMerge\Service\RecordMergeTaskService;

/**
 * 录像合并任务执行器
 *
 * 每 10 秒执行一次：
 * 1. 重置卡住的合并任务（merging 超过 30 分钟）
 * 2. 处理 pending 状态的合并任务
 */
class RecordMergeTask extends BaseCrontabTask
{
    public function execute() : void
    {
        /** @var RecordMergeTaskService $service */
        $service = $this->getBfw()->service('RecordMerge:RecordMergeTaskService');

        // 1. 重置卡住的任务
        $resetCount = $service->resetStuckTasks();

        // 2. 处理 pending 任务
        $processedCount = $service->processPendingTasks();

        if ($resetCount > 0 || $processedCount > 0) {
            $this->log()->info('RecordMergeTask completed', [
                'reset_count'     => $resetCount,
                'processed_count' => $processedCount,
            ]);
        }
    }
}
