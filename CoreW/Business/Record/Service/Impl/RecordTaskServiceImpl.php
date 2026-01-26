<?php

namespace CoreW\Business\Record\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Devices\Enums\MediaServerType;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\Record\Dao\RecordTaskDao;
use CoreW\Business\Record\Enums\RecordTaskStatusEnum;
use CoreW\Business\Record\Enums\RecordTaskTypeEnum;
use CoreW\Business\Record\Service\RecordTaskService;
use CoreW\Dao\DaoProxy;
use CoreW\Exception\ZlmException;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
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
        $startTimestamp = $now;
        $endTimestamp = $now + $durationSec;

        $task = [
            'task_type' => RecordTaskTypeEnum::ALARM->value,
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'media_server_id' => $this->getGb28181Service()->getMediaServerId(),
            'vhost' => '__defaultVhost__',
            'app' => 'rtp',
            'start_time' => $startTimestamp,
            'end_time' => $endTimestamp,
            'customized_path' => $customizedPath ?: null,
            'status' => RecordTaskStatusEnum::PENDING->value,
        ];

        return $this->getRecordTaskDao()->create($task);
    }

    public function createDownloadRecordTask(string $deviceId, string $channelId, string $startTime, string $endTime, string $streamId, string $ssrc, int $downloadSpeed = 1): array
    {
        // 转换为时间戳存储
        $startTimestamp = strtotime($startTime);
        $endTimestamp = strtotime($endTime);
        $duration = $endTimestamp - $startTimestamp;

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
            'start_time' => $startTimestamp,
            'end_time' => $endTimestamp,
            'record_duration' => $duration,  // 预计录制时长
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
            $this->getSystemLogService()->warning('Record', 'execute_task', 'Record task not found', ['task_id' => $taskId]);
            return false;
        }

        if ($task['status'] !== RecordTaskStatusEnum::PENDING->value) {
            $this->getSystemLogService()->warning('Record', 'execute_task', 'Record task not in pending status', [
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
            // start_time 和 end_time 是时间戳，转为 ISO8601 格式
            $startTime = date('Y-m-d\TH:i:s', $task['start_time']);
            $endTime = date('Y-m-d\TH:i:s', $task['end_time']);

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

            $this->getSystemLogService()->info('Record', 'invite_sent', 'Record task INVITE sent', [
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

            $this->getSystemLogService()->error('Record', 'execute_task', 'Execute record task failed', [
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

            $this->getSystemLogService()->info('Record', 'stop_recording', 'Recording stopped', [
                'task_id' => $taskId,
                'stream_id' => $streamId,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->getSystemLogService()->error('Record', 'stop_recording', 'Stop recording failed', [
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

        $this->getSystemLogService()->info('Record', 'media_ready', 'Download task media ready', [
            'task_id' => $task['id'],
            'ssrc' => $ssrc,
        ]);

        return true;
    }

    public function startRecordingForStableRtp(): int
    {
        $tasks = $this->getRecordTaskDao()->findWaitStreamTasksWithMediaServer();
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
        $tasks = $this->getRecordTaskDao()->findRecordingTasksWithMediaServer();
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
            // 从 task 中获取 media_server 信息（通过 JOIN 查询获取）
            $mediaServer = $this->extractMediaServerFromTask($task);
            if (!$mediaServer) {
                $this->getSystemLogService()->warning('Record', 'start_recording', 'Media server not found for task', [
                    'task_id' => $task['id'],
                    'media_server_id' => $task['media_server_id'] ?? '',
                ]);
                return false;
            }

            // 获取 RTP 状态
            $zlmClient = $this->getZlmClient($mediaServer);
            $rtpInfo = $zlmClient->getRtpInfo($streamId);

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
            $recordPath = $this->buildRecordPath($task, $mediaServer);
            $result = $zlmClient->startRecord(
                $task['vhost'],
                $task['app'],
                $streamId,
                1,  // 自定义录制类型，0=HLS,1=MP4
                $recordPath
            );

            if (!$result) {
                $this->getSystemLogService()->warning('Record', 'start_recording', 'Start record failed', [
                    'task_id' => $task['id'],
                    'stream_id' => $streamId,
                ]);

                return false;
            }

            $this->getRecordTaskDao()->update($task['id'], [
                'status' => RecordTaskStatusEnum::RECORDING->value,
                'record_start_time' => time(),
                'customized_path' => $recordPath,
            ]);


            $this->getSystemLogService()->info('Record', 'start_recording', 'Recording started', [
                'task_id' => $task['id'],
                'stream_id' => $streamId,
                'path' => $recordPath,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->getSystemLogService()->error('Record', 'start_recording', 'Check and start recording failed', [
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
            // 从 task 中获取 media_server 信息（通过 JOIN 查询获取）
            $mediaServer = $this->extractMediaServerFromTask($task);
            if (!$mediaServer) {
                $this->getSystemLogService()->warning('Record', 'stop_recording', 'Media server not found for task', [
                    'task_id' => $task['id'],
                    'media_server_id' => $task['media_server_id'] ?? '',
                ]);
                return false;
            }

            // 获取 RTP 状态
            $zlmClient = $this->getZlmClient($mediaServer);
            $rtpInfo = $zlmClient->getRtpInfo($streamId);

            // 判断结束条件（满足任意一个）：
            // A. RTP Server 不存在了
            // B. 已录制时长 >= 期望时长
            $isFinished = false;

            if (!$rtpInfo || empty($rtpInfo['exist'])) {
                // RTP 不存在，设备停止推流
                $isFinished = true;
            } else {
                // 检查已录制时长
                $recordStartTime = $task['record_start_time'];
                if ($recordStartTime) {
                    $recordedSeconds = time() - $recordStartTime;
                    $expectedSeconds = $task['record_duration'];  // 预计录制时长

                    // 已录 >= 80% 预计时长，可以结束
                    if ($recordedSeconds >= ($expectedSeconds * 0.8)) {
                        $isFinished = true;
                    }
                }
            }

            if (!$isFinished) {
                return false;
            }

            // 停止录像
            $zlmClient->stopRecord(
                $task['vhost'],
                $task['app'],
                $streamId,
                0  // 0=MP4
            );

            // 关闭流
            $zlmClient->closeStream('rtp', $streamId);

            // 更新任务状态
            $actualDuration = time() - ($task['record_start_time'] ?? time());
            $this->getRecordTaskDao()->update($task['id'], [
                'status' => RecordTaskStatusEnum::DONE->value,
                'record_end_time' => time(),
                'record_duration' => $actualDuration,
            ]);

            $this->getSystemLogService()->info('Record', 'stop_recording', 'Recording completed', [
                'task_id' => $task['id'],
                'stream_id' => $streamId,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->getSystemLogService()->error('Record', 'stop_recording', 'Check and stop recording failed', [
                'task_id' => $task['id'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 构建录制文件路径
     */
    private function buildRecordPath(array $task, array $mediaServer): string
    {
        if (empty($mediaServer['record_path'])) {
            throw new \RuntimeException('Media server record_path not found');
        }

        $relativePath = '/playback';
//        sprintf(
//            '/playback/%d',
//            $task['id']
//        );

        return rtrim($mediaServer['record_path'], '/') . $relativePath;
    }

    /**
     * 从 task 数组中提取 media_server 信息（JOIN 查询后的数据）
     */
    private function extractMediaServerFromTask(array $task): ?array
    {
        // 检查是否包含 JOIN 查询的 media_server 字段
        if (isset($task['server_id']) && isset($task['type'])) {
            return [
                'id' => $task['media_server_db_id'] ?? null,
                'server_id' => $task['server_id'],
                'type' => $task['type'],
                'host' => $task['host'] ?? '',
                'port' => $task['port'] ?? '',
                'secret' => $task['secret'] ?? '',
                'record_path' => $task['record_path'] ?? '',
                'stream_ip' => $task['stream_ip'] ?? '',
                'status' => $task['media_server_status'] ?? '',
            ];
        }

        return null;
    }

    public function getDownloadTaskByStreamId(string $streamId): ?array
    {
        return $this->getRecordTaskDao()->getDownloadTaskByStreamId($streamId);
    }

    public function deleteRecordTask(int $taskId): bool
    {
        return $this->getRecordTaskDao()->delete($taskId) > 0;
    }

    public function cancelRecordTask(int $taskId): bool
    {
        $task = $this->getRecordTaskDao()->get($taskId);
        if (!$task) {
            return false;
        }

        // 如果正在录制，先停止
        if ($task['status'] === RecordTaskStatusEnum::RECORDING->value) {
            $streamId = $task['stream_id'] ?? '';
            if ($streamId) {
                try {
                    $this->getZlmClientByServerId($task['media_server_id'])->stopRecord(
                        $task['vhost'],
                        $task['app'],
                        $streamId,
                        0
                    );
                    $this->getGb28181Service()->closeStream('rtp', $streamId);
                } catch (\Throwable $e) {
                    $this->getSystemLogService()->warning('Record', 'cancel_task', 'Cancel record task stop record failed', [
                        'task_id' => $taskId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // 删除任务
        return $this->getRecordTaskDao()->delete($taskId) > 0;
    }


    public function getZlmClientByServerId(string $serverId): ZLMClient
    {
        $mediaServer = $this->getMediaService()->getMediaServerByServerId($serverId);
        if (!$mediaServer || $mediaServer['type'] !== MediaServerType::ZLM->value) {
            throw new ZlmException('未找到对应的ZLM');
        }

        return $this->getZlmClient($mediaServer);
    }

    /**
     * @return ZLMClient
     */
    private function getZlmClient(array $config): ZLMClient
    {
        return $this->bfw['zlm_sdk']($config);
    }

    /**
     * 获取媒体服务
     *
     * @return MediaServerService
     */
    protected function getMediaService(): MediaServerService
    {
        return $this->bfw->service('MediaServer:MediaServerService');
    }
    protected function getRecordTaskDao(): RecordTaskDao|DaoProxy
    {
        return $this->createDao('Record:RecordTaskDao');
    }

    protected function getDeviceService()
    {
        return $this->createService('Devices:DeviceService');
    }
}
