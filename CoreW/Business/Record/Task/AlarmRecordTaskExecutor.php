<?php

namespace CoreW\Business\Record\Task;

use CoreW\Business\Common\BaseCrontabTask;
use CoreW\Business\Record\Service\RecordTaskService;
use support\Log;

/**
 * 报警录像任务执行器
 *
 * 每分钟执行一次，处理待执行的录像任务并停止超时的录像
 */
class AlarmRecordTaskExecutor extends BaseCrontabTask
{
    public function execute(): void
    {
        $this->log()->info('AlarmRecordTaskExecutor started');

        try {
            /** @var RecordTaskService $recordTaskService */
            $recordTaskService = $this->getBfw()->offsetGet('Record:RecordTaskService');

            // 1. 处理待执行的任务
            $processedCount = $recordTaskService->processPendingTasks();

            // 2. 停止超时的录像
            $stoppedCount = $recordTaskService->stopExpiredRecordings();

            $this->log()->info('AlarmRecordTaskExecutor completed', [
                'processed_count' => $processedCount,
                'stopped_count' => $stoppedCount,
            ]);

        } catch (\Exception $e) {
            $this->log()->error('AlarmRecordTaskExecutor failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
