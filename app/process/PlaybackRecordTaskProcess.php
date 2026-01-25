<?php

namespace app\process;

use CoreW\Business\Common\BaseCrontabTask;
use CoreW\Business\Record\Service\RecordTaskService;
use support\Log;

/**
 * 回放下载录像任务执行器
 *
 * 每5秒执行一次，处理下载录像任务：
 * 1. 检查并启动等待稳定RTP任务的录像
 * 2. 监控并停止已完成的录像任务
 */
class PlaybackRecordTaskProcess extends BaseCrontabTask
{
    public function execute(): void
    {
        $this->log()->info('PlaybackRecordTaskProcess started');

        try {
            /** @var RecordTaskService $recordTaskService */
            $recordTaskService = $this->getBfw()->service('Record:RecordTaskService');

            // 1. 检查并启动等待稳定RTP的录像任务
            $startedCount = $recordTaskService->startRecordingForStableRtp();

            // 2. 监控并停止已完成的录像任务
            $stoppedCount = $recordTaskService->stopCompletedRecordings();

            $this->log()->info('PlaybackRecordTaskProcess completed', [
                'started_count' => $startedCount,
                'stopped_count' => $stoppedCount,
            ]);

        } catch (\Exception $e) {
            $this->log()->error('PlaybackRecordTaskProcess failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
