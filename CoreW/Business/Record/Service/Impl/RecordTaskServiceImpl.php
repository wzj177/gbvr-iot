<?php

namespace CoreW\Business\Record\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Record\Dao\RecordTaskDao;
use CoreW\Business\Record\Enums\RecordTaskStatusEnum;
use CoreW\Business\Record\Service\RecordTaskService;
use CoreW\Dao\DaoProxy;
use support\Log;

class RecordTaskServiceImpl extends BaseService implements RecordTaskService
{
    public function createAlarmRecordTask(string $deviceId, string $channelId, int $durationSec, string $customizedPath = ''): array
    {
        // 验证设备和通道
        $channel = $this->getDeviceService()->getChannel($deviceId, $channelId);
        if (!$channel) {
            throw new \RuntimeException("Device or channel not found: {$deviceId}/{$channelId}");
        }

        $now = time();
        $startTime = date('Y-m-d H:i:s', $now);
        $endTime = date('Y-m-d H:i:s', $now + $durationSec);

        $task = [
            'task_type' => 'alarm',
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'media_server_id' => $this->getGb28181Service()->getMediaServerId(),
            'vhost' => '__defaultVhost__',
            'app' => 'rtp',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'customized_path' => $customizedPath ?: null,
            'status' => RecordTaskStatusEnum::PENDING->value,
        ];

        return $this->getRecordTaskDao()->create($task);
    }

    public function executeRecordTask(int $taskId): bool
    {
        $task = $this->getRecordTaskDao()->get($taskId);
        if (!$task) {
            Log::channel('sip')->warning('Record task not found', ['task_id' => $taskId]);
            return false;
        }

        if ($task['status'] !== RecordTaskStatusEnum::PENDING->value) {
            Log::channel('sip')->warning('Record task not in pending status', [
                'task_id' => $taskId,
                'status' => $task['status'],
            ]);
            return false;
        }

        try {
            // 更新状态为 INVITING
            $this->getRecordTaskDao()->update($taskId, [
                'status' => RecordTaskStatusEnum::INVITING->value,
            ]);

            // 计算录像时长（用于 INVITE，startPlayback 需要开始和结束时间）
            $startTime = $task['start_time'];
            $endTime = $task['end_time'];

            // 调用 GB28181Service 发起 INVITE
            $result = $this->getGb28181Service()->startPlayback(
                $task['device_id'],
                $task['channel_id'],
                $startTime,
                $endTime,
                'record',  // 专门用于录像的 stream_id 前缀
                $task['customized_path'] ?? ''
            );

            if (!$result) {
                $this->getRecordTaskDao()->update($taskId, [
                    'status' => RecordTaskStatusEnum::FAILED->value,
                    'fail_reason' => 'INVITE failed',
                ]);
                return false;
            }

            // 更新任务状态，记录 stream_id
            $this->getRecordTaskDao()->update($taskId, [
                'status' => RecordTaskStatusEnum::WAIT_STREAM->value,
                'stream_id' => $result['stream_id'] ?? null,
            ]);

            Log::channel('sip')->info('Record task INVITE sent', [
                'task_id' => $taskId,
                'device_id' => $task['device_id'],
                'channel_id' => $task['channel_id'],
                'stream_id' => $result['stream_id'] ?? null,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->getRecordTaskDao()->update($taskId, [
                'status' => RecordTaskStatusEnum::FAILED->value,
                'fail_reason' => $e->getMessage(),
            ]);

            Log::channel('sip')->error('Execute record task failed', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function stopRecording(int $taskId): bool
    {
        $task = $this->getRecordTaskDao()->get($taskId);
        if (!$task) {
            return false;
        }

        if ($task['status'] !== RecordTaskStatusEnum::RECORDING->value) {
            return false;
        }

        try {
            $streamId = $task['stream_id'] ?? null;
            if ($streamId) {
                // 关闭 ZLM 流
                $this->getGb28181Service()->closeStream('rtp', $streamId);
            }

            // 发送 BYE
            $this->getGb28181Service()->stopPlayback(
                $task['device_id'],
                $task['channel_id'],
                $streamId ?? ''
            );

            // 更新任务状态
            $this->getRecordTaskDao()->update($taskId, [
                'status' => RecordTaskStatusEnum::DONE->value,
            ]);

            Log::channel('sip')->info('Recording stopped', [
                'task_id' => $taskId,
                'stream_id' => $streamId,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::channel('sip')->error('Stop recording failed', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function searchRecordTasks(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20): array
    {
        return $this->getRecordTaskDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countRecordTasks(array $conditions): int
    {
        return $this->getRecordTaskDao()->count($conditions);
    }

    public function processPendingTasks(): int
    {
        $tasks = $this->getRecordTaskDao()->findPendingTasks(100);
        $count = 0;

        foreach ($tasks as $task) {
            if ($this->executeRecordTask($task['id'])) {
                $count++;
            }
        }

        return $count;
    }

    public function stopExpiredRecordings(): int
    {
        $tasks = $this->getRecordTaskDao()->findRecordingTasksToStop();
        $count = 0;

        foreach ($tasks as $task) {
            if ($this->stopRecording($task['id'])) {
                $count++;
            }
        }

        return $count;
    }

    protected function getRecordTaskDao(): RecordTaskDao|DaoProxy
    {
        return $this->createDao('Record:RecordTaskDao');
    }

    protected function getDeviceService()
    {
        return $this->createService('Devices:DeviceService');
    }

    protected function getGb28181Service()
    {
        return $this->createService('GB:Gb28181Service');
    }
}
