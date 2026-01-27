<?php

namespace CoreW\Business\Record\Task;

use CoreW\Business\Common\BaseCrontabTask;
use CoreW\Business\Record\Service\RecordTaskService;

/**
 * 回放下载录像任务执行器
 *
 * 每5秒执行一次，处理下载录像任务：
 * 1. 检查并启动等待稳定RTP任务的录像
 * 2. 监控并停止已完成的录像任务（转为 FINALIZING）
 * 3. 完成完成中的任务（兜底机制，处理 hook 未触发的情况）
 */
class PlaybackRecordTask extends BaseCrontabTask
{
    public function execute(): void
    {
//        $this->log()->info('PlaybackRecordTaskProcess started');
//        echo 'PlaybackRecordTaskProcess started', PHP_EOL;
        /** @var RecordTaskService $recordTaskService */
        $recordTaskService = $this->getBfw()->service('Record:RecordTaskService');

        // 1. 检查并启动等待稳定RTP的录像任务
        $startedCount = $recordTaskService->startRecordingForStableRtp();

        // 2. 监控并停止已完成的录像任务（转为 FINALIZING，等待 onRecordMp4 hook）
        $stoppedCount = $recordTaskService->stopCompletedRecordings();

        // 3. 完成完成中的任务（兜底机制，处理 hook 未触发的情况）
        $completedCount = $recordTaskService->completeFinalizingTasks();

        $this->log()->info('PlaybackRecordTaskProcess completed', [
            'started_count' => $startedCount,
            'stopped_count' => $stoppedCount,
            'completed_count' => $completedCount,
        ]);
//        echo 'PlaybackRecordTaskProcess completed', ':started_count=', $startedCount, ',stopped_count=', $stoppedCount, ',completed_count=', $completedCount,PHP_EOL;
    }
}
