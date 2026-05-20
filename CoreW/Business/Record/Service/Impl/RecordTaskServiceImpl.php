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
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Dao\DaoProxy;
use CoreW\Exception\ZlmException;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Redis;

class RecordTaskServiceImpl extends BaseService implements RecordTaskService
{
    public function createAlarmRecordTask(string $deviceId, string $channelId, int $durationSec, string $customizedPath = '') : array
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
            'task_type'       => RecordTaskTypeEnum::ALARM->value,
            'device_id'       => $deviceId,
            'channel_id'      => $channelId,
            'media_server_id' => $this->getGb28181Service()->getMediaServerId(),
            'vhost'           => '__defaultVhost__',
            'app'             => 'rtp',
            'start_time'      => $startTimestamp,
            'end_time'        => $endTimestamp,
            'customized_path' => $customizedPath ? : null,
            'status'          => RecordTaskStatusEnum::PENDING->value,
        ];

        return $this->getRecordTaskDao()->create($task);
    }

    public function createDownloadRecordTask(string $deviceId, string $channelId, string $startTime, string $endTime, string $streamId, string $ssrc, int $downloadSpeed = 1) : array
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
            'task_type'       => RecordTaskTypeEnum::PLAYBACK_DOWNLOAD->value,
            'device_id'       => $deviceId,
            'channel_id'      => $channelId,
            'media_server_id' => $channel['media_server_id'],
            'vhost'           => '__defaultVhost__',
            'app'             => 'rtp',
            'stream_id'       => $streamId,
            'ssrc'            => $ssrc,
            'start_time'      => $startTimestamp,
            'end_time'        => $endTimestamp,
            'record_duration' => $duration,  // 预计录制时长
            'download_speed'  => $downloadSpeed,
            'status'          => RecordTaskStatusEnum::INVITING->value,
        ];

        return $this->getRecordTaskDao()->create($task);
    }

    /**
     * TODO： 执行录像任务-需要改
     * @param int $taskId
     * @return bool
     * @throws \CoreW\Dao\DaoException
     */
    public function executeRecordTask(int $taskId) : bool
    {
        $task = $this->getRecordTaskDao()->get($taskId);
        if (!$task) {
            $this->getLogService()->warning(LogEnum::MODULE_RECORD, LogEnum::ACTION_EXECUTE_TASK, '录像任务未找到', ['task_id' => $taskId]);
            return false;
        }

        if ($task['status'] !== RecordTaskStatusEnum::PENDING->value) {
            $this->getLogService()->warning(LogEnum::MODULE_RECORD, LogEnum::ACTION_EXECUTE_TASK, '录像任务状态非待执行', [
                'task_id' => $taskId,
                'status'  => $task['status'],
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
                    'status'      => RecordTaskStatusEnum::FAILED->value,
                    'fail_reason' => 'INVITE failed',
                ]);
                return false;
            }

            // 更新任务状态，记录 stream_id
            $this->getRecordTaskDao()->update($taskId, [
                'status'    => RecordTaskStatusEnum::WAIT_STREAM->value,
                'stream_id' => $result['stream_id'] ?? null,
            ]);

            $this->getLogService()->info(LogEnum::MODULE_RECORD, LogEnum::ACTION_INVITE_SENT, '录像任务邀请已发送', [
                'task_id'    => $taskId,
                'device_id'  => $task['device_id'],
                'channel_id' => $task['channel_id'],
                'stream_id'  => $result['stream_id'] ?? null,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->getRecordTaskDao()->update($taskId, [
                'status'      => RecordTaskStatusEnum::FAILED->value,
                'fail_reason' => $e->getMessage(),
            ]);

            $this->getLogService()->error(LogEnum::MODULE_RECORD, LogEnum::ACTION_EXECUTE_TASK, '执行录像任务失败', [
                'task_id' => $taskId,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 停止录像-需要改
     * @param int $taskId
     * @return bool
     */
    public function stopRecording(int $taskId) : bool
    {
        return false;
        //        $task = $this->getRecordTaskDao()->get($taskId);
        //        if (!$task) {
        //            return false;
        //        }
        //
        //        if ($task['status'] !== RecordTaskStatusEnum::RECORDING->value) {
        //            return false;
        //        }
        //
        //        try {
        //            $streamId = $task['stream_id'] ?? null;
        //            if ($streamId) {
        //                // 关闭 ZLM 流
        //                $this->getGb28181Service()->closeStream('rtp', $streamId);
        //            }
        //
        //            // 发送 BYE
        //            $this->getGb28181Service()->stopPlayback(
        //                $task['device_id'],
        //                $task['channel_id'],
        //                $streamId ?? ''
        //            );
        //
        //            // 更新任务状态
        //            $this->getRecordTaskDao()->update($taskId, [
        //                'status' => RecordTaskStatusEnum::DONE->value,
        //            ]);
        //
        //            $this->getSystemLogService()->info('Record', 'stop_recording', 'Recording stopped', [
        //                'task_id' => $taskId,
        //                'stream_id' => $streamId,
        //            ]);
        //
        //            return true;
        //
        //        } catch (\Exception $e) {
        //            $this->getSystemLogService()->error('Record', 'stop_recording', 'Stop recording failed', [
        //                'task_id' => $taskId,
        //                'error' => $e->getMessage(),
        //            ]);
        //            return false;
        //        }
    }

    public function searchRecordTasks(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20) : array
    {
        return $this->getRecordTaskDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countRecordTasks(array $conditions) : int
    {
        return $this->getRecordTaskDao()->count($conditions);
    }

    public function processPendingTasks() : int
    {
        $tasks = $this->getRecordTaskDao()->findPendingTasks();
        $count = 0;

        foreach ($tasks as $task) {
            if ($this->executeRecordTask($task['id'])) {
                $count++;
            }
        }

        return $count;
    }

    public function stopExpiredRecordings() : int
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

    public function updateTaskStatusWhenMediaReady(string $ssrc, int $inviteOkTime) : bool
    {
        $task = $this->getRecordTaskDao()->getBySsrc($ssrc);
        if (!$task) {
            return false;
        }

        if ($task['status'] !== RecordTaskStatusEnum::INVITING->value) {
            return false;
        }

        // 检查 ZLM hook 配置，如果未开启或回调地址不对，直接设置时间
        $shouldSetTimeDirectly = false;
        try {
            $mediaServer = $this->getMediaService()->getMediaServerByServerId($task['media_server_id']);
            if ($mediaServer && $mediaServer['type'] === MediaServerType::ZLM->value) {
                $zlmClient = $this->getZlmClient($mediaServer);
                $config = $zlmClient->getServerConfig();

                $hookEnabled = $config['hook.enable'] ?? 0;
                $onPublishHook = $config['hook.on_publish'] ?? '';

                // 检查 hook 是否开启，且回调地址包含 /zlm_hook/on_publish
                if ($hookEnabled != 1 || !str_contains($onPublishHook, '/zlm_hook/on_publish')) {
                    $shouldSetTimeDirectly = true;
                    //                    $this->getSystemLogService()->warning('Record', 'media_ready', 'ZLM hook not properly configured, setting time directly', [
                    //                        'task_id' => $task['id'],
                    //                        'hook_enabled' => $hookEnabled,
                    //                        'on_publish' => $onPublishHook,
                    //                    ]);
                }
            }
        } catch (\Throwable $e) {
            // 获取配置失败，保守处理：直接设置时间
            $shouldSetTimeDirectly = true;

            $this->getLogService()->warning(LogEnum::MODULE_RECORD, LogEnum::ACTION_MEDIA_READY, '获取ZLM配置失败，直接设置时间', [
                'task_id' => $task['id'],
                'error'   => $e->getMessage(),
            ]);
        }

        // 更新状态和 invite_ok_time
        $updateData = [
            'status'         => RecordTaskStatusEnum::WAIT_STREAM->value,
            'invite_ok_time' => $inviteOkTime,
        ];

        // 如果 hook 未配置，直接设置时间（相当于提前填充）
        if ($shouldSetTimeDirectly) {
            $updateData['record_start_time'] = $inviteOkTime;
            $updateData['last_rtp_time'] = $inviteOkTime;
        }

        $this->getRecordTaskDao()->update($task['id'], $updateData);

        $this->getLogService()->info(LogEnum::MODULE_RECORD, LogEnum::ACTION_MEDIA_READY, '下载任务媒体就绪', [
            'task_id'           => $task['id'],
            'ssrc'              => $ssrc,
            'set_time_directly' => $shouldSetTimeDirectly,
        ]);

        return true;
    }

    public function startRecordingForStableRtp() : int
    {
        $tasks = $this->getRecordTaskDao()->findWaitStreamTasksWithMediaServer();
        //        var_dump($tasks);
        $count = 0;

        foreach ($tasks as $task) {
            if ($this->checkAndStartRecording($task)) {
                $count++;
            }
        }

        return $count;
    }

    public function stopCompletedRecordings() : int
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

    public function completeFinalizingTasks() : int
    {
        $tasks = $this->getRecordTaskDao()->findFinalizingTasks();
        $count = 0;

        foreach ($tasks as $task) {
            if ($this->checkAndCompleteRecording($task)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 检查并启动录像（等待稳定 RTP）
     *
     * 状态转换条件：WAIT_STREAM → RECORDING
     * - last_rtp_time > 0（RTP 存在）
     * - time() - last_rtp_time >= 3（RTP 稳定至少 3 秒）
     */
    private function checkAndStartRecording(array $task) : bool
    {
        $streamId = $task['stream_id'] ?? '';
        if (!$streamId) {
            return false;
        }

        try {
            // 从 task 中获取 media_server 信息（通过 JOIN 查询获取）
            $mediaServer = $this->extractMediaServerFromTask($task);
            if (!$mediaServer) {
                $this->getLogService()->warning(LogEnum::MODULE_RECORD, LogEnum::ACTION_START_RECORDING, '任务找不到媒体服务器', [
                    'task_id'         => $task['id'],
                    'media_server_id' => $task['media_server_id'] ?? '',
                ]);
                return false;
            }

            $zlmClient = $this->getZlmClient($mediaServer);

            // 获取 RTP 状态
            $rtpInfo = $zlmClient->getRtpInfo($streamId);
            $rtpExists = $rtpInfo && isset($rtpInfo['exist']) && $rtpInfo['exist'];
            // 更新 last_rtp_time（如果 RTP 存在）
            if ($rtpExists) {
                $this->updateLastRtpTime($task['id'], time());
            }

            // 使用 last_rtp_time 判断状态转换条件
            $lastRtpTime = $task['last_rtp_time'] ?? 0;

            // 判断 RTP 稳定条件：
            // 1. last_rtp_time > 0（RTP 存在或曾经存在）
            // 2. time() - last_rtp_time >= 3（RTP 稳定至少 3 秒）
            if ($lastRtpTime <= 0 || (time() - $lastRtpTime) < 3) {
                return false;
            }

            // 检查是否已经在录制
            $isRecording = $zlmClient->isRecording(
                $task['vhost'],
                $task['app'],
                $streamId,
                1
            );

            if ($isRecording) {
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
                $this->getLogService()->warning(LogEnum::MODULE_RECORD, LogEnum::ACTION_START_RECORDING, '开始录像失败', [
                    'task_id'   => $task['id'],
                    'stream_id' => $streamId,
                ]);

                return false;
            }

            $this->getRecordTaskDao()->update($task['id'], [
                'status'            => RecordTaskStatusEnum::RECORDING->value,
                'record_start_time' => $task['record_start_time'] > 0 ? $task['record_start_time'] : time(),
                'customized_path'   => $recordPath,
            ]);


            $this->getLogService()->info(LogEnum::MODULE_RECORD, LogEnum::ACTION_START_RECORDING, '录像已开始', [
                'task_id'   => $task['id'],
                'stream_id' => $streamId,
                'path'      => $recordPath,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_RECORD, LogEnum::ACTION_START_RECORDING, '检查和开始录像失败', [
                'task_id' => $task['id'],
                'error'   => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * 检查并停止录像（监控结束）
     *
     * 状态转换条件：RECORDING → FINALIZING
     * - time() - last_rtp_time >= 10（RTP 超时 10 秒）
     *
     * 注意：不设置 record_end_time 和 record_duration
     * 这些字段由 onRecordMp4 hook 根据实际文件信息填充
     */
    private function checkAndStopRecording(array $task) : bool
    {
        $streamId = $task['stream_id'] ?? '';
        if (!$streamId) {
            return false;
        }

        try {
            // 从 task 中获取 media_server 信息（通过 JOIN 查询获取）
            $mediaServer = $this->extractMediaServerFromTask($task);
            if (!$mediaServer) {
                $this->getLogService()->warning(LogEnum::MODULE_RECORD, LogEnum::ACTION_STOP_RECORDING, '任务找不到媒体服务器', [
                    'task_id'         => $task['id'],
                    'media_server_id' => $task['media_server_id'] ?? '',
                ]);
                return false;
            }

            $zlmClient = $this->getZlmClient($mediaServer);
            // 获取 RTP 状态
            $rtpInfo = $zlmClient->getRtpInfo($streamId);
            $rtpExists = $rtpInfo && isset($rtpInfo['exist']) && $rtpInfo['exist'];
            // 更新 last_rtp_time（如果 RTP 存在）
            if ($rtpExists) {
                $this->updateLastRtpTime($task['id'], time());
            }

            // 使用 last_rtp_time 判断状态转换条件
            $lastRtpTime = $task['last_rtp_time'] ?? 0;

            // 判断结束条件：time() - last_rtp_time >= 10（RTP 超时 10 秒）
            if ($lastRtpTime <= 0 || (time() - $lastRtpTime) < 10) {
                return false;
            }

            // 停止录像
            $zlmClient->stopRecord(
                $task['vhost'],
                $task['app'],
                $streamId,
                0  // 0=MP4
            );

            // 转换到 FINALIZING 状态，等待 onRecordMp4 hook 填充 record_end_time
            $this->getRecordTaskDao()->update($task['id'], [
                'status' => RecordTaskStatusEnum::FINALIZING->value,
            ]);

            $this->getLogService()->info(LogEnum::MODULE_RECORD, LogEnum::ACTION_STOP_RECORDING, '录像正在完成，等待mp4钩子', [
                'task_id'   => $task['id'],
                'stream_id' => $streamId,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_RECORD, LogEnum::ACTION_STOP_RECORDING, '检查和停止录像失败', [
                'task_id' => $task['id'],
                'error'   => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * 检查并完成录像（最终化）
     *
     * 状态转换条件：FINALIZING → DONE
     * - 计算最终录制时长
     * - 更新任务状态为 DONE
     */
    private function checkAndCompleteRecording(array $task) : bool
    {
        try {
            // 计算实际录制时长
            $recordStartTime = $task['record_start_time'] ?? 0;
            $recordEndTime = $task['record_end_time'] ?? time();

            if ($recordStartTime <= 0) {
                $this->getLogService()->warning(LogEnum::MODULE_RECORD, LogEnum::ACTION_COMPLETE_RECORDING, '未找到录像开始时间', [
                    'task_id' => $task['id'],
                ]);
                return false;
            }

            $actualDuration = $recordEndTime - $recordStartTime;

            // 更新任务状态为 DONE
            $this->getRecordTaskDao()->update($task['id'], [
                'status'          => RecordTaskStatusEnum::DONE->value,
                'record_end_time' => $recordEndTime,
                'record_duration' => $actualDuration,
            ]);

            // 删除同一 stream_id 的其他任务（排除当前任务ID）
            $streamId = $task['stream_id'] ?? '';
            if ($streamId) {
                $deletedCount = $this->getRecordTaskDao()->deleteOtherTasksByStreamId($streamId, $task['id']);
                if ($deletedCount > 0) {
                    $this->getLogService()->info(LogEnum::MODULE_RECORD, LogEnum::ACTION_COMPLETE_RECORDING, '已删除相同stream_id的其他任务', [
                        'task_id'       => $task['id'],
                        'stream_id'     => $streamId,
                        'deleted_count' => $deletedCount,
                    ]);
                }
            }

            $this->getLogService()->info(LogEnum::MODULE_RECORD, LogEnum::ACTION_COMPLETE_RECORDING, '录像已完成', [
                'task_id'   => $task['id'],
                'stream_id' => $task['stream_id'] ?? '',
                'duration'  => $actualDuration,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->getLogService()->error(LogEnum::MODULE_RECORD, LogEnum::ACTION_COMPLETE_RECORDING, '完成录像失败', [
                'task_id' => $task['id'],
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * 构建录制文件路径
     */
    private function buildRecordPath(array $task, array $mediaServer) : string
    {
        if (empty($mediaServer['record_path'])) {
            return '';
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
    private function extractMediaServerFromTask(array $task) : ?array
    {
        // 检查是否包含 JOIN 查询的 media_server 字段
        if (isset($task['server_id']) && isset($task['type'])) {
            return [
                'id'          => $task['media_server_db_id'] ?? null,
                'server_id'   => $task['server_id'],
                'type'        => $task['type'],
                'host'        => $task['host'] ?? '',
                'port'        => $task['port'] ?? '',
                'secret'      => $task['secret'] ?? '',
                'record_path' => $task['record_path'] ?? '',
                'stream_ip'   => $task['stream_ip'] ?? '',
                'status'      => $task['media_server_status'] ?? '',
            ];
        }

        return null;
    }

    public function getValidityDownloadTaskByStreamId(string $streamId) : ?array
    {
        return $this->getRecordTaskDao()->getValidityTaskByStreamId($streamId);
    }

    public function getDoneRecordTaskByStreamId(string $streamId) : ?array
    {
        return $this->getRecordTaskDao()->getDoneByStreamId($streamId);
    }

    public function getByStreamId(string $streamId) : ?array
    {
        return $this->getRecordTaskDao()->getByStreamId($streamId);
    }

    public function deleteRecordTask(int $taskId) : bool
    {
        return $this->getRecordTaskDao()->delete($taskId) > 0;
    }

    public function cancelRecordTask(int $taskId) : bool
    {
        $task = $this->getRecordTaskDao()->get($taskId);
        if (!$task) {
            return false;
        }

        // 如果正在录制，先停止
        if ($task['status'] === RecordTaskStatusEnum::RECORDING->value) {
            $streamId = $task['stream_id'] ?? '';
            if ($streamId) {
                $zlmClient = $this->getZlmClientByServerId($task['media_server_id']);
                try {
                    $isRecording = $zlmClient->isRecording(
                        $task['vhost'],
                        $task['app'],
                        $streamId,
                        1
                    );
                    if ($isRecording) {
                        $zlmClient->stopRecord(
                            $task['vhost'],
                            $task['app'],
                            $streamId,
                            0
                        );
                        $zlmClient->closeStream('rtp', $streamId);
                    }
                } catch (\Throwable $e) {
                    $this->getLogService()->warning(LogEnum::MODULE_RECORD, LogEnum::ACTION_CANCEL_TASK, '取消录像任务停止录像失败', [
                        'task_id' => $taskId,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        // 删除任务
        return $this->getRecordTaskDao()->delete($taskId) > 0;
    }


    public function getZlmClientByServerId(string $serverId) : ZLMClient
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
    private function getZlmClient(array $config) : ZLMClient
    {
        return $this->bfw['zlm_sdk']($config);
    }

    /**
     * 获取媒体服务
     *
     * @return MediaServerService
     */
    protected function getMediaService() : MediaServerService
    {
        return $this->bfw->service('MediaServer:MediaServerService');
    }

    public function updateRecordStartTimeByStreamId(string $streamId, string $mediaServerId, int $time) : void
    {
        $this->getRecordTaskDao()->updateRecordStartTimeByStreamId($streamId, $mediaServerId, $time);
    }

    public function updateRecordEndTimeByStreamId(string $streamId, string $mediaServerId, int $time) : void
    {
        $this->getRecordTaskDao()->updateRecordEndTimeByStreamId($streamId, $mediaServerId, $time);
    }

    public function updateLastRtpTime(int $taskId, int $time) : void
    {
        $this->getRecordTaskDao()->update($taskId, [
            'last_rtp_time' => $time,
        ]);
    }

    public function completeTaskFromHook(int $taskId, int $endTime, int $duration) : void
    {
        $task = $this->getRecordTaskDao()->get($taskId);
        if (!$task) {
            return;
        }

        // 只处理 FINALIZING 状态的任务
        if ($task['status'] !== RecordTaskStatusEnum::FINALIZING->value) {
            $this->getLogService()->warning(LogEnum::MODULE_RECORD, LogEnum::ACTION_COMPLETE_FROM_HOOK, '任务状态非完成中', [
                'task_id' => $taskId,
                'status'  => $task['status'],
            ]);
            return;
        }

        $streamId = $task['stream_id'] ?? '';

        // 更新任务状态为 DONE，设置 record_end_time 和 record_duration
        $this->getRecordTaskDao()->update($taskId, [
            'status'          => RecordTaskStatusEnum::DONE->value,
            'record_end_time' => $endTime,
            'record_duration' => $duration,
        ]);

        // 删除同一 stream_id 的其他任务（排除当前任务ID）
        if ($streamId) {
            $deletedCount = $this->getRecordTaskDao()->deleteOtherTasksByStreamId($streamId, $taskId);
            if ($deletedCount > 0) {
                $this->getLogService()->info(LogEnum::MODULE_RECORD, LogEnum::ACTION_COMPLETE_FROM_HOOK, '已删除相同stream_id的其他任务', [
                    'task_id'       => $taskId,
                    'stream_id'     => $streamId,
                    'deleted_count' => $deletedCount,
                ]);
            }
        }

        $this->getLogService()->info(LogEnum::MODULE_RECORD, LogEnum::ACTION_COMPLETE_FROM_HOOK, '任务从钩子完成', [
            'task_id'   => $taskId,
            'stream_id' => $streamId,
            'end_time'  => $endTime,
            'duration'  => $duration,
        ]);
    }

    protected function getRecordTaskDao() : RecordTaskDao|DaoProxy
    {
        return $this->createDao('Record:RecordTaskDao');
    }

    protected function getDeviceService()
    {
        return $this->createService('Devices:DeviceService');
    }
}
