<?php

namespace CoreW\Business\Record\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface RecordTaskDao extends AdvancedDaoInterface
{
    /**
     * 查找待执行的录像任务
     */
    public function findPendingTasks(int $limit = 100) : array;

    /**
     * 查找需要停止的录像任务（超时或达到结束时间）
     */
    public function findRecordingTasksToStop() : array;

    /**
     * 根据stream_id查找任务
     */
    public function getByStreamId(string $streamId) : ?array;


    /**
     * 根据stream_id查找已完成的任务
     */
    public function getDoneByStreamId(string $streamId) : ?array;

    /**
     * 根据ssrc查找任务
     */
    public function getBySsrc(string $ssrc) : ?array;

    /**
     * 获取下载任务（根据 stream_id）
     */
    public function getValidityTaskByStreamId(string $streamId) : ?array;


    /**
     * 查找等待稳定RTP的下载任务（带 media_server 信息）
     * 只查询最近1小时内创建的任务
     */
    public function findWaitStreamTasksWithMediaServer() : array;

    /**
     * 查找正在录制的下载任务（带 media_server 信息）
     * 只查询最近1小时内创建的任务
     */
    public function findRecordingTasksWithMediaServer() : array;

    /**
     * 根据 stream_id 和 media_server_id 更新 record_start_time（仅当为 0 时）
     */
    public function updateRecordStartTimeByStreamId(string $streamId, string $mediaServerId, int $time) : int;

    /**
     * 根据 stream_id 和 media_server_id 更新 record_end_time（仅当为 0 时）
     */
    public function updateRecordEndTimeByStreamId(string $streamId, string $mediaServerId, int $time) : int;

    /**
     * 更新 last_rtp_time
     */
    public function updateLastRtpTime(int $taskId, int $time) : int;

    /**
     * 查找完成中的任务（finalizing 状态）
     */
    public function findFinalizingTasks() : array;

    /**
     * 删除同一 stream_id 的其他任务（排除指定的任务ID）
     * 当某个 stream_id 的任务完成时，删除该 stream_id 下所有其他状态的记录
     */
    public function deleteOtherTasksByStreamId(string $streamId, int $excludeTaskId) : int;
}
