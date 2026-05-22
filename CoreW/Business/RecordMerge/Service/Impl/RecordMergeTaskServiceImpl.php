<?php

namespace CoreW\Business\RecordMerge\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\RecordMerge\Dao\RecordMergeTaskDao;
use CoreW\Business\RecordMerge\Exception\RecordMergeException;
use CoreW\Business\RecordMerge\Service\RecordMergeTaskService;
use CoreW\Business\RecordFile\Dao\RecordFileDao;
use CoreW\Dao\DaoInterface;
use CoreW\Dao\DaoProxy;
use support\Log;

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

        // 查询时间范围内的录像文件
        $files = $this->getRecordFileDao()->search(
            [
                'device_id'  => $deviceId,
                'channel_id' => $channelId,
            ],
            ['start_time' => 'ASC'],
            0,
            1000
        );

        // 过滤出时间范围内的文件
        $matchedFiles = [];
        foreach ($files as $file) {
            $fileStart = (int)$file['start_time'];
            $fileEnd = (int)$file['end_time'];
            // 文件时间段与查询范围有交集
            if ($fileStart < $endTime && $fileEnd > $startTime) {
                $matchedFiles[] = $file;
            }
        }

        if (empty($matchedFiles)) {
            throw RecordMergeException::NO_FILES_IN_RANGE();
        }

        $fileIds = array_column($matchedFiles, 'id');
        $now = date('Y-m-d H:i:s');

        $fields = [
            'device_id'         => $deviceId,
            'channel_id'        => $channelId,
            'start_time'        => $startTime,
            'end_time'          => $endTime,
            'source_file_ids'   => $fileIds,
            'source_file_count' => count($fileIds),
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
                Log::channel('default')->error("RecordMerge: task #{$task['id']} failed: " . $e->getMessage());
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
     * 执行 FFmpeg 合并
     */
    private function doMerge(array $task) : void
    {
        $fileIds = $task['source_file_ids'] ?? [];
        if (empty($fileIds)) {
            throw new \RuntimeException('源文件ID列表为空');
        }

        // 查询源文件
        $files = $this->getRecordFileDao()->search(
            ['ids' => $fileIds],
            ['start_time' => 'ASC'],
            0,
            1000
        );

        if (empty($files)) {
            throw new \RuntimeException('未找到源录像文件');
        }

        // 检查文件是否都存在
        $validFiles = [];
        foreach ($files as $file) {
            $path = $file['video_path'] ?? '';
            if (!empty($path) && file_exists($path)) {
                $validFiles[] = $file;
            }
        }

        if (empty($validFiles)) {
            throw new \RuntimeException('所有源录像文件均不可访问');
        }

        // 按时间排序
        usort($validFiles, fn($a, $b) => (int)$a['start_time'] <=> (int)$b['start_time']);

        // 生成 concat 文件列表
        $concatListPath = sys_get_temp_dir() . '/merge_' . $task['id'] . '_' . uniqid() . '.txt';
        $fp = fopen($concatListPath, 'w');
        foreach ($validFiles as $file) {
            // ffmpeg concat 格式：file 'path'
            fwrite($fp, "file '" . addslashes($file['video_path']) . "'\n");
        }
        fclose($fp);

        // 生成输出路径
        $outputDir = dirname($validFiles[0]['video_path']) . '/merge';
        if (!is_dir($outputDir)) {
            @mkdir($outputDir, 0755, true);
        }

        $outputPath = $outputDir . '/merge_' . $task['id'] . '_' . date('YmdHis') . '.mp4';

        // FFmpeg concat demuxer（-c copy 不重编码，速度极快）
        $ffmpegCmd = sprintf(
            'ffmpeg -y -f concat -safe 0 -i %s -c copy %s 2>&1',
            escapeshellarg($concatListPath),
            escapeshellarg($outputPath)
        );

        exec($ffmpegCmd, $output, $returnCode);

        // 清理临时文件
        @unlink($concatListPath);

        if ($returnCode !== 0) {
            @unlink($outputPath);
            throw new \RuntimeException('FFmpeg合并失败: ' . implode("\n", array_slice($output, -5)));
        }

        if (!file_exists($outputPath)) {
            throw new \RuntimeException('合并后文件不存在');
        }

        $fileSize = filesize($outputPath);
        $totalDuration = 0;
        foreach ($validFiles as $file) {
            $totalDuration += (int)($file['duration'] ?? 0);
        }

        // 更新任务为完成
        $this->getRecordMergeTaskDao()->update((int)$task['id'], [
            'status'           => 'done',
            'output_path'      => $outputPath,
            'output_file_size' => $fileSize,
            'output_duration'  => $totalDuration,
            'finished_at'      => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }

    private function formatTasks(array $tasks) : array
    {
        return array_map(fn($t) => $this->formatTask($t), $tasks);
    }

    private function formatTask(array $task) : array
    {
        $task['start_time_formatted'] = date('Y-m-d H:i:s', (int)$task['start_time']);
        $task['end_time_formatted'] = date('Y-m-d H:i:s', (int)$task['end_time']);
        $task['output_duration_formatted'] = $task['output_duration'] ? gmdate('H:i:s', (int)$task['output_duration']) : null;
        $task['output_file_size_mb'] = $task['output_file_size'] ? round($task['output_file_size'] / 1048576, 2) : 0;
        return $task;
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
