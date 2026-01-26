<?php

namespace CoreW\Business\Record\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface RecordTaskDao extends AdvancedDaoInterface
{
    /**
     * 查找待执行的录像任务
     */
    public function findPendingTasks(int $limit = 100): array;

    /**
     * 查找需要停止的录像任务（超时或达到结束时间）
     */
    public function findRecordingTasksToStop(): array;

    /**
     * 根据stream_id查找任务
     */
    public function getByStreamId(string $streamId): ?array;

    /**
     * 根据ssrc查找任务
     */
    public function getBySsrc(string $ssrc): ?array;

    /**
     * 查找等待稳定RTP的下载任务（wait_stream状态）
     */
    public function findWaitStreamTasks(int $limit = 100): array;

    /**
     * 查找正在录制的下载任务（recording状态）
     */
    public function findRecordingTasks(int $limit = 100): array;

    /**
     * 获取下载任务（根据 stream_id）
     */
    public function getDownloadTaskByStreamId(string $streamId): ?array;

    /**
     * 查找等待稳定RTP的下载任务（带 media_server 信息）
     * 只查询最近1小时内创建的任务
     */
    public function findWaitStreamTasksWithMediaServer(): array;

    /**
     * 查找正在录制的下载任务（带 media_server 信息）
     * 只查询最近1小时内创建的任务
     */
    public function findRecordingTasksWithMediaServer(): array;
}
