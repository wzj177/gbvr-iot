<?php

namespace CoreW\Business\RecordFile\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\RecordFile\Service\RecordFileService;
use CoreW\Business\RecordFile\Dao\RecordFileDao;
use CoreW\Business\Record\Service\RecordTaskService;
use CoreW\Dao\DaoProxy;
use support\Redis;

class RecordFileServiceImpl extends BaseService implements RecordFileService
{
    /**
     * 从 ZLM hook 创建录像文件记录
     *
     * @param array $hookData ZLM on_record_mp4 hook 数据
     * @param string $mediaServerId 媒体服务器 ID
     * @return array|null 创建的记录，失败返回 null
     */
    public function createFromHook(array $hookData, string $mediaServerId): ?array
    {
        $streamId = $hookData['stream'] ?? '';
        $app = $hookData['app'] ?? '';
        $vhost = $hookData['vhost'] ?? '__defaultVhost__';

        if (empty($streamId)) {
            $this->getSystemLogService()->warning('RecordFile', 'create_from_hook', 'stream_id is empty', $hookData);
            return null;
        }

        $isDownload = str_contains($streamId, 'download_');

        // 查找对应的录像任务
        // 注意：使用 getByStreamId 不过滤状态，因为 hook 触发时任务可能是 FINALIZING 或其他状态
        $recordTask = null;
        if ($isDownload) {
            $recordTask = $this->getRecordTaskService()->getByStreamId($streamId);
        }

        // 如果找不到任务，也可能是通道直播录像（stream_id 格式为 device_id_channel_id）
        // 可以通过 device_channels 表查找
        $deviceId = '';
        $channelId = '';
        $sourceId = null;
        $sourceDesc = '';

        if ($recordTask) {
            $deviceId = $recordTask['device_id'] ?? '';
            $channelId = $recordTask['channel_id'] ?? '';
            $sourceType = 'playback_download';
            $sourceId = $recordTask['id'];
            $sourceDesc = '回放下载录像任务';

            // 如果任务是 FINALIZING 状态，更新为 DONE 并设置 record_end_time 和 record_duration
            if ($recordTask['status'] === 'finalizing') {
                // ZLM hook 提供的是 start_time 和 time_len
                $hookStartTime = (int)($hookData['start_time'] ?? 0);
                $timeLen = (float)($hookData['time_len'] ?? 0);
                $hookEndTime = (int)($hookStartTime + $timeLen);
                $duration = (int)$timeLen;

                $this->getRecordTaskService()->completeTaskFromHook($recordTask['id'], $hookEndTime, $duration);

                $this->getSystemLogService()->info('RecordFile', 'create_from_hook', 'Task completed from hook', [
                    'task_id' => $recordTask['id'],
                    'stream_id' => $streamId,
                    'record_end_time' => $hookEndTime,
                    'duration' => $duration,
                ]);
            }
        } else {
            $parts = explode('_', $streamId);
            if (count($parts) >= 2) {
                $deviceId = $parts[0];
                $channelId = $parts[1];
            }

            $this->getSystemLogService()->warning('RecordFile', 'create_from_hook', 'Record task not found for stream_id', [
                'device_id' => $deviceId,
                'channel_id' => $channelId,
                'stream_id' => $streamId,
            ]);
            return null;
        }

        // 解析文件路径和时间信息
        // ZLM hook 提供的字段：
        // - start_time: 开始时间戳
        // - time_len: 录制时长（秒，浮点数）
        // - file_size: 文件大小（字节）
        $filePath = $hookData['file_path'] ?? '';
        $fileName = $hookData['file_name'] ?? basename($filePath);
        $fileSize = (int)($hookData['file_size'] ?? 0);
        $startTime = (int)($hookData['start_time'] ?? 0);
        $timeLen = (float)($hookData['time_len'] ?? 0);
        $duration = (int)$timeLen;
        $endTime = $startTime + $duration;

        // 构建 video_path（完整路径）
        $videoPath = $filePath;

        // 构建 record_date（从 startTime 转换）
        $recordDate = $startTime > 0 ? date('Y-m-d', $startTime) : date('Y-m-d');

        // 构建 download_url（如果有）
        $downloadUrl = '';

        $data = [
            'main_id' => $streamId,
            'media_server_id' => $mediaServerId,
            'media_server_ip' => $hookData['media_ip'] ?? '',
            'channel_id' => $channelId,
            'channel_name' => $recordTask['channel_name'] ?? '',
            'device_id' => $deviceId,
            'source_type' => $sourceType,
            'video_src_url' => '',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'duration' => $duration,
            'video_path' => $videoPath,
            'file_size' => $fileSize,
            'vhost' => $vhost,
            'stream_id' => $streamId,
            'app' => $app,
            'download_url' => $downloadUrl,
            'is_undo' => 1,
            'record_date' => $recordDate,
            'source_id' => $sourceId,
            'source_desc' => $sourceDesc,
            'delete_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'plan_id' => 0,
        ];

        try {
            $recordFile = $this->getRecordFileDao()->createRecordFile($data);

            $this->getSystemLogService()->info('RecordFile', 'create_from_hook', 'Record file created from hook', [
                'stream_id' => $streamId,
                'file_path' => $filePath,
                'duration' => $duration,
            ]);

            return $recordFile;
        } catch (\Throwable $e) {
            $this->getSystemLogService()->error('RecordFile', 'create_from_hook', 'Failed to create record file', [
                'stream_id' => $streamId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function searchRecordFiles(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20): array
    {
        return $this->getRecordFileDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countRecordFiles(array $conditions): int
    {
        return $this->getRecordFileDao()->count($conditions);
    }

    /**
     * @return RecordFileDao|DaoProxy
     */
    protected function getRecordFileDao(): RecordFileDao|DaoProxy
    {
        return $this->createDao('RecordFile:RecordFileDao');
    }

    /**
     * @return RecordTaskService
     */
    protected function getRecordTaskService(): RecordTaskService
    {
        return $this->createService('Record:RecordTaskService');
    }
}
