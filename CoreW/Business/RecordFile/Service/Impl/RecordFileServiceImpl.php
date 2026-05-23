<?php

namespace CoreW\Business\RecordFile\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\RecordFile\Service\RecordFileService;
use CoreW\Business\RecordFile\Dao\RecordFileDao;
use CoreW\Business\Record\Service\RecordTaskService;
use CoreW\Business\StreamProxy\Service\StreamProxyService;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Dao\DaoProxy;
use support\Redis;
use CoreW\Business\MediaServer\Service\MediaServerService;

class RecordFileServiceImpl extends BaseService implements RecordFileService
{
    /**
     * 从 ZLM hook 创建录像文件记录
     *
     * @param array $hookData ZLM on_record_mp4 hook 数据
     * @param string $mediaServerId 媒体服务器 ID
     * @return array|null 创建的记录，失败返回 null
     */
    public function createFromHook(array $hookData, string $mediaServerId) : ?array
    {
        $streamId = $hookData['stream'] ?? '';
        $app = $hookData['app'] ?? '';
        $vhost = $hookData['vhost'] ?? '__defaultVhost__';

        if (empty($streamId)) {
            $this->getLogService()->warning(LogEnum::MODULE_RECORD_FILE, LogEnum::ACTION_CREATE_FROM_HOOK, 'stream_id为空', $hookData);
            return null;
        }

        $deviceId = '';
        $channelId = '';
        $sourceType = '';
        $sourceId = null;
        $sourceDesc = '';
        $planId = 0;
        $recordTask = null;

        if (str_contains($streamId, 'download_')) {
            // ===== 回放下载录像 =====
            $recordTask = $this->getRecordTaskService()->getByStreamId($streamId);
            if (!$recordTask) {
                $this->getLogService()->warning(LogEnum::MODULE_RECORD_FILE, LogEnum::ACTION_CREATE_FROM_HOOK, '找不到 download stream_id 对应的录像任务', [
                    'stream_id' => $streamId,
                ]);
                return null;
            }

            $deviceId = $recordTask['device_id'] ?? '';
            $channelId = $recordTask['channel_id'] ?? '';
            $sourceType = 'playback_download';
            $sourceId = $recordTask['id'];
            $sourceDesc = '回放下载录像任务';

            if ($recordTask['status'] === 'finalizing') {
                $hookStartTime = (int)($hookData['start_time'] ?? 0);
                $timeLen = (float)($hookData['time_len'] ?? 0);
                $hookEndTime = (int)($hookStartTime + $timeLen);
                $this->getRecordTaskService()->completeTaskFromHook($recordTask['id'], $hookEndTime, (int)$timeLen);
                $this->getLogService()->info(LogEnum::MODULE_RECORD_FILE, LogEnum::ACTION_CREATE_FROM_HOOK, '下载任务从 hook 完成', [
                    'task_id'         => $recordTask['id'],
                    'stream_id'       => $streamId,
                    'record_end_time' => $hookEndTime,
                    'duration'        => (int)$timeLen,
                ]);
            }

        } else if ($app === 'rtp') {
            // ===== GB28181 云端录像（AutoRecordProcess::startRecord 的 RTP 流）=====
            $channels = $this->getDeviceService()->searchChannels(['stream_id' => $streamId], [], 0, 1);
            $channel = $channels[0] ?? null;

            if (!$channel) {
                $this->getLogService()->warning(LogEnum::MODULE_RECORD_FILE, LogEnum::ACTION_CREATE_FROM_HOOK, '找不到 stream_id 对应的通道', [
                    'stream_id' => $streamId,
                    'app'       => $app,
                ]);
                return null;
            }

            $deviceId = $channel['device_id'];
            $channelId = $channel['channel_id'];
            $sourceType = 'cloud_plan';
            $sourceId = $channel['record_plan_id'] ?? 0;
            $sourceDesc = '云端录像';
            $planId = $sourceId;

        } else {
            // ===== 流代理录像（StreamProxy 绑定录像计划）=====
            $proxies = $this->getStreamProxyService()->searchProxies(['app' => $app, 'stream' => $streamId], [], 0, 1);
            $proxy = $proxies[0] ?? null;

            if (!$proxy || empty($proxy['record_plan_id'])) {
                $this->getLogService()->warning(LogEnum::MODULE_RECORD_FILE, LogEnum::ACTION_CREATE_FROM_HOOK, '找不到 stream 对应的流代理或未绑定录像计划', [
                    'stream_id' => $streamId,
                    'app'       => $app,
                ]);
                return null;
            }

            $sourceType = 'stream_proxy_record';
            $sourceId = $proxy['id'];
            $sourceDesc = '流代理录像';
            $planId = (int)$proxy['record_plan_id'];
        }

        // 解析文件路径和时间信息
        $filePath = $hookData['file_path'] ?? '';
        $fileSize = (int)($hookData['file_size'] ?? 0);
        $startTime = (int)($hookData['start_time'] ?? 0);
        $timeLen = (float)($hookData['time_len'] ?? 0);
        $duration = (int)$timeLen;
        $endTime = $startTime + $duration;
        $recordDate = $startTime > 0 ? date('Y-m-d', $startTime) : date('Y-m-d');

        $data = [
            'main_id'         => $streamId,
            'media_server_id' => $mediaServerId,
            'media_server_ip' => $hookData['media_ip'] ?? '',
            'channel_id'      => $channelId,
            'channel_name'    => $recordTask['channel_name'] ?? '',
            'device_id'       => $deviceId,
            'source_type'     => $sourceType,
            'video_src_url'   => '',
            'start_time'      => $startTime,
            'end_time'        => $endTime,
            'duration'        => $duration,
            'video_path'      => $filePath,
            'file_size'       => $fileSize,
            'vhost'           => $vhost,
            'stream_id'       => $streamId,
            'app'             => $app,
            'download_url'    => '',
            'is_undo'         => 1,
            'record_date'     => $recordDate,
            'source_id'       => $sourceId,
            'source_desc'     => $sourceDesc,
            'delete_at'       => null,
            'created_at'      => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
            'plan_id'         => $planId,
        ];

        try {
            $recordFile = $this->getRecordFileDao()->createRecordFile($data);
            $this->getLogService()->info(LogEnum::MODULE_RECORD_FILE, LogEnum::ACTION_CREATE_FROM_HOOK, '从 hook 创建录像文件', [
                'stream_id'   => $streamId,
                'source_type' => $sourceType,
                'file_path'   => $filePath,
                'duration'    => $duration,
                'plan_id'     => $planId,
            ]);
            return $recordFile;
        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_RECORD_FILE, LogEnum::ACTION_CREATE_FROM_HOOK, '创建录像文件失败', [
                'stream_id' => $streamId,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function searchRecordFiles(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20) : array
    {
        return $this->getRecordFileDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countRecordFiles(array $conditions) : int
    {
        return $this->getRecordFileDao()->count($conditions);
    }

    public function getRecordFileDateListByPlanId(int $planId) : array
    {
        return $this->getRecordFileDao()->getRecordFileDateListByPlanId($planId);
    }

    public function getRecordFileSizeByPlanId(int $planId) : int
    {
        return $this->getRecordFileDao()->getRecordFileSizeByPlanId($planId);
    }

    public function softDeleteByPlanIdAndDate(int $planId, string $recordDate) : int
    {
        return $this->getRecordFileDao()->softDeleteByPlanIdAndDate($planId, $recordDate);
    }

    public function searchRecordFilesWithDeviceInfo(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20) : array
    {
        $list = $this->getRecordFileDao()->search($conditions, $orderBys, $start, $limit);
        $list = $this->formatFiles($list, $this->buildMediaServerMap($list));
        foreach ($list as &$recordFile) {
            unset($recordFile['video_path']);
        }

        return $list;
    }

    public function batchDeleteByIds(array $ids) : array
    {
        if (empty($ids)) {
            return ['deleted' => 0, 'file_errors' => 0];
        }

        // 查询记录，获取 video_path
        $files = $this->getRecordFileDao()->findByIds($ids);

        $fileErrors = 0;
        foreach ($files as $file) {
            $videoPath = $file['video_path'] ?? '';
            if (!empty($videoPath) && file_exists($videoPath)) {
                if (!@unlink($videoPath)) {
                    $fileErrors++;
                    $this->getLogService()->warning(LogEnum::MODULE_RECORD_FILE, 'batch_delete', '删除录像文件失败', [
                        'id'         => $file['id'],
                        'video_path' => $videoPath,
                        'error'      => error_get_last()['message'] ?? 'unknown',
                    ]);
                }
            }
        }

        // 删除数据库记录
        $this->getRecordFileDao()->batchDelete(['ids' => $ids]);

        $this->getLogService()->info(LogEnum::MODULE_RECORD_FILE, 'batch_delete', '批量删除录像文件', [
            'ids'         => $ids,
            'db_deleted'  => 1,
            'file_errors' => $fileErrors,
        ]);

        return ['deleted' => count($ids), 'file_errors' => $fileErrors];
    }


    /**
     * 格式化录像文件数据
     *
     * @param array $files
     * @param array $mediaServersMap server_id => ['host'=>..., 'https_port'=>...]
     */
    private function formatFiles(array $files, array $mediaServersMap = []) : array
    {
        return array_map(function ($file) use ($mediaServersMap) {
            $file['start_time_formatted'] = $file['start_time'] ? date('Y-m-d H:i:s', $file['start_time']) : null;
            $file['end_time_formatted'] = $file['end_time'] ? date('Y-m-d H:i:s', $file['end_time']) : null;
            $file['duration_formatted'] = $file['duration'] ? gmdate('H:i:s', $file['duration']) : null;
            $file['file_size_mb'] = $file['file_size'] ? round($file['file_size'] / 1048576, 2) : 0;
            $file['video_url'] = $this->buildVideoUrl($file, $mediaServersMap);

            return $file;
        }, $files);
    }

    /**
     * 构建录像播放 URL
     * 格式：https://{host}:{https_port}/record/{app}/{stream_id}/{record_date}/{filename}
     */
    private function buildVideoUrl(array $file, array $mediaServersMap) : string
    {
        $videoPath = $file['video_path'] ?? '';
        if (empty($videoPath)) {
            return '';
        }

        $serverId = $file['media_server_id'] ?? '';
        $server = $mediaServersMap[$serverId] ?? null;
        if (!$server) {
            return '';
        }

        $host = $server['host'];
        $httpsPort = (int)($server['https_port'] ?? 443);
        $app = $file['app'] ?? 'rtp';
        $streamId = $file['stream_id'] ?? '';
        $date = $file['record_date'] ?? '';
        $filename = basename($videoPath);

        return "https://{$host}:{$httpsPort}/record/{$app}/{$streamId}/{$date}/{$filename}";
    }

    /**
     * 批量获取媒体服务器信息，返回以 server_id 为 key 的 map
     */
    private function buildMediaServerMap(array $files) : array
    {
        $serverIds = array_values(array_unique(array_filter(array_column($files, 'media_server_id'))));
        if (empty($serverIds)) {
            return [];
        }

        $servers = $this->getMediaServerService()->findServersByServerIds($serverIds);
        $map = [];
        foreach ($servers as $server) {
            $map[$server['server_id']] = $server;
        }

        return $map;
    }

    protected function getMediaServerService() : MediaServerService
    {
        return $this->createService('MediaServer:MediaServerService');
    }

    /**
     * @return RecordFileDao|DaoProxy
     */
    protected function getRecordFileDao() : RecordFileDao|DaoProxy
    {
        return $this->createDao('RecordFile:RecordFileDao');
    }

    /**
     * @return RecordTaskService
     */
    protected function getRecordTaskService() : RecordTaskService
    {
        return $this->createService('Record:RecordTaskService');
    }

    /**
     * @return DeviceService
     */
    protected function getDeviceService() : DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * @return StreamProxyService
     */
    protected function getStreamProxyService() : StreamProxyService
    {
        return $this->createService('StreamProxy:StreamProxyService');
    }
}
