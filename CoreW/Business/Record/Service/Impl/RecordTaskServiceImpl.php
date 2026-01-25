<?php

namespace CoreW\Business\Record\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Record\Dao\RecordTaskDao;
use CoreW\Business\Record\Enums\RecordTaskStatusEnum;
use CoreW\Business\Record\Enums\RecordTaskTypeEnum;
use CoreW\Business\Record\Service\RecordTaskService;
use CoreW\Dao\DaoProxy;
use support\Log;
use support\Redis;

class RecordTaskServiceImpl extends BaseService implements RecordTaskService
{
    public function createAlarmRecordTask(string $deviceId, string $channelId, int $durationSec, string $customizedPath = ''): array
    {
        // 验证设备和通道
        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);
        if (!$channel) {
            throw new \RuntimeException("Device or channel not found: {$deviceId}/{$channelId}");
        }

        $now = time();
        $startTime = date('Y-m-d H:i:s', $now);
        $endTime = date('Y-m-d H:i:s', $now + $durationSec);

        $task = [
            'task_type' => RecordTaskTypeEnum::ALARM->value,
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'media_server_id' => $this->getGb28181Service()->getMediaServerId(),
            'vhost' => '__defaultVhost__',
            'app' => 'rtp',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'customized_path' => $customizedPath ?: null,
            'task_type' => RecordTaskTypeEnum::ALARM->value,
            'status' => RecordTaskStatusEnum::PENDING->value,
        ];

        return $this->getRecordTaskDao()->create($task);
    }

    public function createDownloadRecordTask(string $deviceId, string $channelId, string $startTime, string $endTime, string $streamId, string $ssrc, int $downloadSpeed = 1): array
    {
        // 转换时间格式为数据库格式
        $dbStartTime = date('Y-m-d H:i:s', strtotime($startTime));
        $dbEndTime = date('Y-m-d H:i:s', strtotime($endTime));

        // 获取通道信息
        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);
        if (!$channel) {
            throw new \RuntimeException("Device or channel not found: {$deviceId}/{$channelId}");
        }

        $task = [
            'task_type' => RecordTaskTypeEnum::PLAYBACK_DOWNLOAD->value,
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'media_server_id' => $channel['media_server_id'],
            'vhost' => '__defaultVhost__',
            'app' => 'rtp',
            'stream_id' => $streamId,
            'ssrc' => $ssrc,
            'start_time' => $dbStartTime,
            'end_time' => $dbEndTime,
            'download_speed' => $downloadSpeed,
            'status' => RecordTaskStatusEnum::INVITING->value,
        ];

        return $this->getRecordTaskDao()->create($task);
    }

    /**
     * 执行录像任务-需要改
     * @param int $taskId
     * @return bool
     * @throws \CoreW\Dao\DaoException
     */
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

    /**
     * 停止录像-需要改
     * @param int $taskId
     * @return bool
     */
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

    public function updateTaskStatusWhenMediaReady(string $ssrc): bool
    {
        $task = $this->getRecordTaskDao()->getBySsrc($ssrc);
        if (!$task) {
            return false;
        }

        if ($task['status'] !== RecordTaskStatusEnum::INVITING->value) {
            return false;
        }

        $this->getRecordTaskDao()->update($task['id'], [
            'status' => RecordTaskStatusEnum::WAIT_STREAM->value,
        ]);

        Log::channel('sip')->info('Download task media ready', [
            'task_id' => $task['id'],
            'ssrc' => $ssrc,
        ]);

        return true;
    }

    public function startRecordingForStableRtp(): int
    {
        $tasks = $this->getRecordTaskDao()->findWaitStreamTasks(1000);
        $count = 0;

        foreach ($tasks as $task) {
            if ($this->checkAndStartRecording($task)) {
                $count++;
            }
        }

        return $count;
    }

    public function stopCompletedRecordings(): int
    {
        $tasks = $this->getRecordTaskDao()->findRecordingTasks(100);
        $count = 0;

        foreach ($tasks as $task) {
            if ($this->checkAndStopRecording($task)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 检查并启动录像（等待稳定 RTP）
     */
    private function checkAndStartRecording(array $task): bool
    {
        $streamId = $task['stream_id'] ?? '';
        if (!$streamId) {
            return false;
        }

        try {
            // 获取 RTP 状态
            $rtpInfo = $this->getZLMClient()->getRtpInfo($streamId);

            // 判断 RTP 稳定条件：
            // 1. RTP 存在（exist = true）
            // 2. 持续时间 >= 3 秒（通过 created_time 判断，避免录制黑屏）
            if (!$rtpInfo || empty($rtpInfo['exist'])) {
                return false;
            }

            // 获取任务创建时间，检查是否已持续至少3秒
            $taskCreated = strtotime($task['created_at']);
            if (time() - $taskCreated < 3) {
                return false;
            }

            // 启动录像
            $recordPath = $this->buildRecordPath($task);
            $result = $this->getZLMClient()->startRecord(
                $task['vhost'],
                $task['app'],
                $streamId,
                0,  // 自定义录制类型，0=MP4
                $recordPath
            );

            if (!$result) {
                Log::channel('sip')->warning('Start record failed', [
                    'task_id' => $task['id'],
                    'stream_id' => $streamId,
                ]);
                return false;
            }

            $this->getRecordTaskDao()->update($task['id'], [
                'status' => RecordTaskStatusEnum::RECORDING->value,
                'record_start_time' => date('Y-m-d H:i:s'),
                'file_path' => $recordPath,
            ]);

            Log::channel('sip')->info('Recording started', [
                'task_id' => $task['id'],
                'stream_id' => $streamId,
                'path' => $recordPath,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::channel('sip')->error('Check and start recording failed', [
                'task_id' => $task['id'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 检查并停止录像（监控结束）
     */
    private function checkAndStopRecording(array $task): bool
    {
        $streamId = $task['stream_id'] ?? '';
        if (!$streamId) {
            return false;
        }

        try {
            // 获取 RTP 状态
            $rtpInfo = $this->getZLMClient()->getRtpInfo($streamId);

            // 判断结束条件（满足任意一个）：
            // A. RTP Server 不存在了
            // B. 已录制时长 >= 期望时长
            $isFinished = false;

            if (!$rtpInfo || empty($rtpInfo['exist'])) {
                // RTP 不存在，设备停止推流
                $isFinished = true;
            } else {
                // 检查已录制时长
                $recordStartTime = strtotime($task['record_start_time'] ?? $task['created_at']);
                $recordedSeconds = time() - $recordStartTime;

                // 期望时长
                $startTime = strtotime($task['start_time']);
                $endTime = strtotime($task['end_time']);
                $expectedSeconds = $endTime - $startTime;

                // 条件 B: 已录 >= 期望时长
                if ($recordedSeconds >= $expectedSeconds) {
                    $isFinished = true;
                }
            }

            if (!$isFinished) {
                return false;
            }

            // 停止录像
            $this->getZLMClient()->stopRecord(
                $task['vhost'],
                $task['app'],
                $streamId,
                0  // 0=MP4
            );

            // 关闭流
            $this->getGb28181Service()->closeStream('rtp', $streamId);

            // 更新任务状态
            $this->getRecordTaskDao()->update($task['id'], [
                'status' => RecordTaskStatusEnum::DONE->value,
                'record_end_time' => date('Y-m-d H:i:s'),
            ]);

            Log::channel('sip')->info('Recording completed', [
                'task_id' => $task['id'],
                'stream_id' => $streamId,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::channel('sip')->error('Check and stop recording failed', [
                'task_id' => $task['id'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 构建录制文件路径
     */
    private function buildRecordPath(array $task): string
    {
        $path = sprintf(
            '/playback/%s/%s/%d',
            date('Y/m'),
            $task['device_id'],
            $task['id']
        );

        return $path;
    }

    protected function getZLMClient()
    {
        return $this->createService('ZLMediaKit:ZLMClient');
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
