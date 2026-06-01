<?php

namespace CoreW\Business\RecordMerge\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\RecordMerge\Dao\RecordMergeTaskDao;
use CoreW\Business\RecordMerge\Exception\RecordMergeException;
use CoreW\Business\RecordMerge\Service\RecordMergeTaskService;
use CoreW\Business\RecordFile\Dao\RecordFileDao;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Service\Gb28181Service;
use CoreW\Dao\DaoInterface;
use CoreW\Dao\DaoProxy;
use support\Log;
use support\utils\ArrayToolkit;
use CoreW\Business\MediaServer\Service\MediaServerService;

class RecordMergeTaskServiceImpl extends BaseService implements RecordMergeTaskService
{
    public function createMergeTask(string $deviceId, string $channelId, int $startTime, int $endTime) : array
    {
        if ($startTime >= $endTime) {
            throw RecordMergeException::INVALID_TIME_RANGE();
        }

        // 检查是否已存在相同范围的任务
        $existing = $this->getRecordMergeTaskDao()->findExistingMerge($deviceId, $channelId, $startTime, $endTime);
        if (!empty($existing)) {
            throw RecordMergeException::MERGE_ALREADY_EXISTS();
        }

        // 获取通道信息（包含 media_server_id）
        $channel = $this->getDeviceService()->getChannelByChannelId($channelId);
        if (empty($channel)) {
            throw RecordMergeException::CHANNEL_NOT_FOUND();
        }

        $now = date('Y-m-d H:i:s');

        $fields = [
            'device_id'         => $deviceId,
            'channel_id'        => $channelId,
            'media_server_id'   => $channel['media_server_id'],
            'start_time'        => $startTime,
            'end_time'          => $endTime,
            'source_file_ids'   => [],
            'source_file_count' => 0,
            'status'            => 'pending',
            'output_path'       => '',
            'output_file_size'  => 0,
            'output_duration'   => 0,
            'error_message'     => '',
            'created_at'        => $now,
            'updated_at'        => $now,
        ];

        return $this->getRecordMergeTaskDao()->create($fields);
    }

    public function searchMergeTasks(array $conditions, array $orderBys, int $start, int $limit) : array
    {
        $rows = $this->getRecordMergeTaskDao()->search($conditions, $orderBys, $start, $limit);
        return $this->formatTasks($rows);
    }

    public function countMergeTasks(array $conditions) : int
    {
        return $this->getRecordMergeTaskDao()->count($conditions);
    }

    public function getMergeTask(int $id) : ?array
    {
        $task = $this->getRecordMergeTaskDao()->get($id);
        if ($task) {
            return $this->formatTask($task);
        }
        return null;
    }

    public function deleteMergeTask(int $id) : bool
    {
        $task = $this->getRecordMergeTaskDao()->get($id);
        if (empty($task)) {
            throw RecordMergeException::MERGE_TASK_NOT_FOUND();
        }

        if (!in_array($task['status'], ['done', 'failed'])) {
            throw RecordMergeException::CANNOT_CANCEL();
        }

        // 删除合并后的物理文件
        if ($task['status'] === 'done' && !empty($task['output_path']) && file_exists($task['output_path'])) {
            @unlink($task['output_path']);
        }

        return $this->getRecordMergeTaskDao()->delete($id);
    }

    public function cancelMergeTask(int $id) : bool
    {
        $task = $this->getRecordMergeTaskDao()->get($id);
        if (empty($task)) {
            throw RecordMergeException::MERGE_TASK_NOT_FOUND();
        }

        if ($task['status'] !== 'pending') {
            throw RecordMergeException::CANNOT_CANCEL();
        }

        $this->getRecordMergeTaskDao()->update($id, [
            'status'        => 'failed',
            'error_message' => '用户取消',
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    public function processPendingTasks() : int
    {
        $tasks = $this->getRecordMergeTaskDao()->search(
            ['status' => 'pending'],
            ['id' => 'ASC'],
            0,
            5
        );
        $processed = 0;
        foreach ($tasks as $task) {
            // CAS 原子抢占
            $affected = $this->getRecordMergeTaskDao()->claimTask((int)$task['id']);
            if ($affected === 0) {
                continue;
            }

            try {
                $this->doMerge($task);
                $processed++;
            } catch (\Throwable $e) {
                Log::channel('crontab')->error("RecordMerge: task #{$task['id']} failed: " . $e->getMessage());
                $this->getRecordMergeTaskDao()->update((int)$task['id'], [
                    'status'        => 'failed',
                    'error_message' => mb_substr($e->getMessage(), 0, 500),
                    'finished_at'   => date('Y-m-d H:i:s'),
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return $processed;
    }

    public function resetStuckTasks() : int
    {
        $stuckTasks = $this->getRecordMergeTaskDao()->findStuckTasks(30);
        $count = 0;
        foreach ($stuckTasks as $task) {
            $this->getRecordMergeTaskDao()->update((int)$task['id'], [
                'status'        => 'pending',
                'started_at'    => null,
                'error_message' => '合并超时，自动重试',
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * 执行 FFmpeg 合并（从 ZLM HTTP URL）
     */
    private function doMerge(array $task) : void
    {
        $mediaServerId = (int)($task['media_server_id'] ?? 0);
        if ($mediaServerId === 0) {
            throw new \RuntimeException('任务缺少 media_server_id');
        }

        // 获取通道信息
        $channel = $this->getDeviceService()->getChannelByChannelId($task['channel_id']);
        if (empty($channel)) {
            throw new \RuntimeException('通道不存在');
        }

        // 获取 ZLM Client
        $zlmClient = $this->getZlmClientByServerId($mediaServerId);
        $mediaServer = $this->getMediaServerService()->getMediaServer($mediaServerId);

        // 获取录像文件列表（ZLM 返回本地路径数组）
        $period = date('Y-m-d', (int)$task['start_time']);
        $files = $zlmClient->getMp4RecordFile(
            '__defaultVhost__',
            $channel['app'],
            $channel['stream_id'],
            $period
        );

        if (empty($files)) {
            throw new \RuntimeException('ZLM 无录像文件');
        }

        // 过滤出时间范围内的文件
        $startTime = (int)$task['start_time'];
        $endTime = (int)$task['end_time'];
        $matchedFiles = [];

        foreach ($files as $filePath) {
            $file = $this->parseZlmRecordPath($filePath);
            $fileTime = strtotime($file['record_date']);

            if ($fileTime >= $startTime && $fileTime < $endTime) {
                $matchedFiles[] = $file;
            }
        }

        if (empty($matchedFiles)) {
            throw new \RuntimeException('指定时间范围内无录像文件');
        }

        // 构建输入 HTTP URL
        $inputUrls = [];
        $mediaServersMap = [$mediaServer['id'] => $mediaServer];

        foreach ($matchedFiles as $file) {
            $inputUrls[] = $this->buildVideoUrl($file, $mediaServersMap);
        }

        // 生成本地输出路径
        $outputDir = storage_path('record_merge/' . $mediaServerId);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }
        $outputFile = $outputDir . '/merged_' . date('Ymd_His') . '_' . $task['id'] . '.mp4';
        $relativePath = 'record_merge/' . $mediaServerId . '/' . basename($outputFile);

        // FFmpeg concat（HTTP URL 输入）
        $ffmpegCmd = $this->buildFfmpegConcatCommand($inputUrls, $outputFile);

        exec($ffmpegCmd . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            @unlink($outputFile);
            throw new \RuntimeException('FFmpeg 合并失败: ' . implode("\n", array_slice($output, -5)));
        }

        if (!file_exists($outputFile)) {
            throw new \RuntimeException('合并后文件不存在');
        }

        $fileSize = filesize($outputFile);
        $totalDuration = ($endTime - $startTime);

        // 更新任务为完成
        $this->getRecordMergeTaskDao()->update((int)$task['id'], [
            'status'           => 'done',
            'output_path'      => $relativePath,
            'output_file_size' => $fileSize,
            'output_duration'  => $totalDuration,
            'finished_at'      => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 解析 ZLM 录像路径
     * 输入: /path/to/record/{app}/{stream_id}/{record_date}/{filename}
     */
    private function parseZlmRecordPath(string $filePath) : array
    {
        $parts = explode('/', trim($filePath, '/'));
        $recordIndex = array_search('record', $parts);

        if ($recordIndex === false || $recordIndex + 4 >= count($parts)) {
            return [
                'app' => '',
                'stream_id' => '',
                'record_date' => '',
                'file_name' => '',
            ];
        }

        return [
            'app' => $parts[$recordIndex + 1] ?? '',
            'stream_id' => $parts[$recordIndex + 2] ?? '',
            'record_date' => $parts[$recordIndex + 3] ?? '',
            'file_name' => $parts[$recordIndex + 4] ?? '',
        ];
    }

    /**
     * 构建 FFmpeg concat 命令（HTTP URL 输入）
     */
    private function buildFfmpegConcatCommand(array $inputUrls, string $outputFile) : string
    {
        $listFile = sys_get_temp_dir() . '/ffmpeg_concat_' . getmypid() . '_' . time() . '.txt';
        $listContent = '';

        foreach ($inputUrls as $url) {
            $listContent .= "file '{$url}'\n";
        }

        file_put_contents($listFile, $listContent);

        // 注册清理函数
        register_shutdown_function(function() use ($listFile) {
            @unlink($listFile);
        });

        return sprintf(
            'ffmpeg -y -f concat -safe 0 -i %s -c copy %s',
            escapeshellarg($listFile),
            escapeshellarg($outputFile)
        );
    }

    /**
     * 构建录像播放 URL
     */
    private function buildVideoUrl(array $file, array $mediaServersMap) : string
    {
        $mediaServer = current($mediaServersMap);
        if (empty($mediaServer)) {
            return '';
        }

        $protocol = $mediaServer['https_port'] ? 'https' : 'http';
        $port = $mediaServer['https_port'] ?: $mediaServer['http_port'];

        return sprintf(
            '%s://%s:%d/record/%s/%s/%s/%s',
            $protocol,
            $mediaServer['host'],
            $port,
            $file['app'],
            $file['stream_id'],
            $file['record_date'],
            $file['file_name']
        );
    }

    private function formatTasks(array $tasks) : array
    {
        return array_map(fn($t) => $this->formatTask($t), $tasks);
    }

    private function buildMediaServerMap(array $serverIds) : array
    {
        $servers = $this->getMediaServerService()->findServersByServerIds($serverIds);

        return ArrayToolkit::index($servers, 'server_id');
    }

    private function formatTask(array $task) : array
    {
        $task['start_time_formatted'] = date('Y-m-d H:i:s', (int)$task['start_time']);
        $task['end_time_formatted'] = date('Y-m-d H:i:s', (int)$task['end_time']);
        $task['output_duration_formatted'] = $task['output_duration'] ? gmdate('H:i:s', (int)$task['output_duration']) : null;

        $task['output_file_size_mb'] = $task['output_file_size'] ? round($task['output_file_size'] / 1048576, 2) : 0;

        // 构建播放 URL（如果是相对路径）
        if (!empty($task['output_path']) && strpos($task['output_path'], '/') !== 0) {
            $mediaServerId = (int)($task['media_server_id'] ?? 0);
            if ($mediaServerId > 0) {
                $mediaServer = $this->getMediaServerService()->getMediaServer($mediaServerId);
                if ($mediaServer) {
                    $protocol = $mediaServer['https_port'] ? 'https' : 'http';
                    $host = $mediaServer['host'] ?? 'localhost';
                    $port = $mediaServer['https_port'] ?: $mediaServer['http_port'];
                    $task['output_url'] = sprintf(
                        '%s://%s:%d/%s',
                        $protocol,
                        $host,
                        $port,
                        $task['output_path']
                    );
                }
            }
        }

        return $task;
    }


    protected function getMediaServerService() : MediaServerService
    {
        return $this->createService('MediaServer:MediaServerService');
    }

    protected function getDeviceService() : DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    protected function getZlmClientByServerId(int $serverId)
    {
        return $this->getGb28181Service()->getZlmClientByServerId($serverId);
    }

    protected function getGb28181Service() : Gb28181Service
    {
        return $this->createService('GB:Gb28181Service');
    }

    protected function getRecordMergeTaskDao() : RecordMergeTaskDao|DaoInterface|DaoProxy
    {
        return $this->createDao('RecordMerge:RecordMergeTaskDao');
    }

    protected function getRecordFileDao() : RecordFileDao|DaoInterface|DaoProxy
    {
        return $this->createDao('RecordFile:RecordFileDao');
    }
}
