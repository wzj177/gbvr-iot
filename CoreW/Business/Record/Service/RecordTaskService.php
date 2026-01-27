<?php

namespace CoreW\Business\Record\Service;

interface RecordTaskService
{
    /**
     * 创建报警录像任务
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $durationSec 录像时长（秒）
     * @param string $customizedPath 自定义存储路径
     * @return array 创建的任务
     */
    public function createAlarmRecordTask(string $deviceId, string $channelId, int $durationSec, string $customizedPath = ''): array;

    /**
     * 创建回放下载录像任务
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $startTime 开始时间（ISO8601格式）
     * @param string $endTime 结束时间（ISO8601格式）
     * @param string $streamId 流ID
     * @param string $ssrc SSRC
     * @param int $downloadSpeed 下载倍速
     * @return array 创建的任务
     */
    public function createDownloadRecordTask(string $deviceId, string $channelId, string $startTime, string $endTime, string $streamId, string $ssrc, int $downloadSpeed = 1): array;

    /**
     * 执行录像任务
     *
     * @param int $taskId 任务ID
     * @return bool
     */
    public function executeRecordTask(int $taskId): bool;

    /**
     * 停止录像
     *
     * @param int $taskId 任务ID
     * @return bool
     */
    public function stopRecording(int $taskId): bool;

    /**
     * 查询录像任务
     *
     * @param array $conditions 查询条件
     * @param array $orderBys 排序
     * @param int $start 起始位置
     * @param int $limit 每页数量
     * @return array
     */
    public function searchRecordTasks(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20): array;

    /**
     * 统计录像任务数量
     *
     * @param array $conditions 查询条件
     * @return int
     */
    public function countRecordTasks(array $conditions): int;

    /**
     * 处理待执行的任务（定时任务调用）
     *
     * @return int 处理的任务数量
     */
    public function processPendingTasks(): int;

    /**
     * 停止超时的录像任务（定时任务调用）
     *
     * @return int 停止的任务数量
     */
    public function stopExpiredRecordings(): int;

    /**
     * 媒体流就绪时更新任务状态（INVITE 200 OK）
     *
     * @param string $ssrc 设备SSRC
     * @param int $inviteOkTime INVITE成功时间戳
     * @return bool
     */
    public function updateTaskStatusWhenMediaReady(string $ssrc, int $inviteOkTime): bool;

    /**
     * 检查并启动等待稳定的RTP任务的录像（定时任务调用）
     *
     * @return int 启动的任务数量
     */
    public function startRecordingForStableRtp(): int;

    /**
     * 监控并停止已完成的录像任务（定时任务调用）
     *
     * @return int 停止的任务数量
     */
    public function stopCompletedRecordings(): int;

    /**
     * 完成完成中的任务（定时任务调用）
     *
     * @return int 完成的任务数量
     */
    public function completeFinalizingTasks(): int;

    /**
     * 获有效期内下载任务（根据 stream_id）
     *
     * @param string $streamId 流ID
     * @return array|null 任务信息，不存在返回 null
     */
    public function getValidityDownloadTaskByStreamId(string $streamId): ?array;

    public function getDoneRecordTaskByStreamId(string $streamId): ?array;

    /**
     * 根据 stream_id 获取任务（不过滤状态）
     * 用于 onRecordMp4 hook 查找任务，因为 hook 触发时机可能在任务状态更新之前
     *
     * @param string $streamId 流ID
     * @return array|null 任务信息，不存在返回 null
     */
    public function getByStreamId(string $streamId): ?array;

    /**
     * 删除录像任务
     *
     * @param int $taskId 任务ID
     * @return bool
     */
    public function deleteRecordTask(int $taskId): bool;

    /**
     * 取消录像任务（停止录制并删除）
     *
     * @param int $taskId 任务ID
     * @return bool
     */
    public function cancelRecordTask(int $taskId): bool;

    /**
     * Hook 更新：根据 stream_id 和 media_server_id 更新 record_start_time（如果为 0）
     *
     * @param string $streamId 流ID
     * @param string $mediaServerId 媒体服务器ID
     * @param int $time 时间戳
     * @return void
     */
    public function updateRecordStartTimeByStreamId(string $streamId, string $mediaServerId, int $time): void;

    /**
     * Hook 更新：根据 stream_id 和 media_server_id 更新 record_end_time（如果为 0）
     *
     * @param string $streamId 流ID
     * @param string $mediaServerId 媒体服务器ID
     * @param int $time 时间戳
     * @return void
     */
    public function updateRecordEndTimeByStreamId(string $streamId, string $mediaServerId, int $time): void;

    /**
     * Scheduler 更新：更新 last_rtp_time
     *
     * @param int $taskId 任务ID
     * @param int $time 时间戳
     * @return void
     */
    public function updateLastRtpTime(int $taskId, int $time): void;

    /**
     * Hook 更新：从 onRecordMp4 hook 完成任务
     *
     * 将 FINALIZING 状态的任务更新为 DONE，并设置 record_end_time 和 record_duration
     * 时间来自 ZLM hook 的实际文件信息
     *
     * @param int $taskId 任务ID
     * @param int $endTime 录制结束时间戳（来自 hook）
     * @param int $duration 录制时长（秒）
     * @return void
     */
    public function completeTaskFromHook(int $taskId, int $endTime, int $duration): void;
}
