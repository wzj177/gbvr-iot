<?php

namespace CoreW\Business\Snapshot\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Snapshot\Dao\SnapshotFileDao;
use CoreW\Business\Snapshot\Service\SnapshotFileService;
use CoreW\Dao\DaoProxy;
use support\Log;

class SnapshotFileServiceImpl extends BaseService implements SnapshotFileService
{
    public function captureAlarmSnapshot(string $deviceId, string $channelId, int $alarmEventId, string $imageFormat = 'JPEG') : array
    {
        // 检查设备和通道
        $channel = $this->getDeviceService()->getChannel($deviceId, $channelId);
        if (!$channel) {
            throw new \RuntimeException("Device or channel not found: {$deviceId}/{$channelId}");
        }

        // 检查是否有活跃流
        $streamId = $channel['stream_id'] ?? null;
        if ($streamId) {
            // 从活跃流抓拍
            $snapshot = $this->captureFromStream($deviceId, $channelId, $streamId, 'alarm', $alarmEventId);
            if ($snapshot) {
                return $snapshot;
            }
        }

        // 如果没有活跃流，通过 INVITE 抓拍
        return $this->captureAfterInvite($deviceId, $channelId, 'alarm', $alarmEventId) ?? [];
    }

    public function captureFromStream(string $deviceId, string $channelId, string $streamId, string $sourceType = 'manual', ?int $sourceId = null, int $timeoutSec = 5) : ?array
    {
        try {
            // 获取流信息
            $streamInfo = $this->getZlmClient()->getStreamInfo($streamId);
            if (!$streamInfo || !isset($streamInfo['app']) || !isset($streamInfo['stream'])) {
                Log::channel('sip')->warning('Stream not found for snapshot', [
                    'stream_id' => $streamId,
                ]);
                return null;
            }

            $app = $streamInfo['app'];
            $stream = $streamInfo['stream'];
            $vhost = $streamInfo['vhost'] ?? '__defaultVhost__';

            // 调用 ZLM getSnap 抓拍
            $snapResult = $this->getZlmClient()->getSnap($vhost, $app, $stream, 0, 0);

            if (!$snapResult || !isset($snapResult['snap_path'])) {
                Log::channel('sip')->warning('Snapshot failed', [
                    'stream_id' => $streamId,
                    'result'    => $snapResult,
                ]);
                return null;
            }

            // 保存快照记录
            $snapshot = [
                'device_id'       => $deviceId,
                'channel_id'      => $channelId,
                'channel_name'    => $this->getChannelName($deviceId, $channelId),
                'source_type'     => $sourceType,
                'source_id'       => $sourceId,
                'source_desc'     => $this->getSourceDesc($sourceType, $sourceId),
                'shot_time'       => date('Y-m-d H:i:s.v'),
                'file_path'       => $snapResult['snap_path'],
                'file_url'        => $snapResult['snap_url'] ?? null,
                'file_size'       => $snapResult['file_size'] ?? 0,
                'format'          => $this->getImageFormat($snapResult['snap_path']),
                'width'           => $snapResult['width'] ?? null,
                'height'          => $snapResult['height'] ?? null,
                'media_server_id' => $snapResult['media_server_id'] ?? null,
                'media_server_ip' => $snapResult['media_server_ip'] ?? null,
                'vhost'           => $vhost,
                'app'             => $app,
                'stream_id'       => $streamId,
                'asset_id'        => $this->generateAssetId(),
                'index_status'    => 'none',
            ];

            return $this->getSnapshotFileDao()->create($snapshot);

        } catch (\Exception $e) {
            Log::channel('sip')->error('Capture from stream failed', [
                'device_id'  => $deviceId,
                'channel_id' => $channelId,
                'stream_id'  => $streamId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function captureAfterInvite(string $deviceId, string $channelId, string $sourceType = 'manual', ?int $sourceId = null, int $timeoutSec = 5) : ?array
    {
        try {
            // 发起 INVITE
            $result = $this->getGb28181Service()->startLiveVideo($deviceId, $channelId, 'snapshot');

            if (!$result || !isset($result['stream_id'])) {
                Log::channel('sip')->warning('INVITE failed for snapshot', [
                    'device_id'  => $deviceId,
                    'channel_id' => $channelId,
                ]);
                return null;
            }

            $streamId = $result['stream_id'];

            // 等待流就绪
            $startTime = time();
            $streamReady = false;

            while (time() - $startTime < $timeoutSec) {
                usleep(200000); // 200ms

                $streamInfo = $this->getZlmClient()->getStreamInfo($streamId);
                if ($streamInfo && isset($streamInfo['app']) && isset($streamInfo['stream'])) {
                    $streamReady = true;
                    break;
                }
            }

            if (!$streamReady) {
                // 清理：发送 BYE
                $this->getGb28181Service()->stopLiveVideo($deviceId, $channelId, $streamId);
                Log::channel('sip')->warning('Stream not ready for snapshot', [
                    'device_id'  => $deviceId,
                    'channel_id' => $channelId,
                ]);
                return null;
            }

            // 从流抓拍
            $snapshot = $this->captureFromStream($deviceId, $channelId, $streamId, $sourceType, $sourceId);

            // 抓拍完成后发送 BYE 停止视频
            $this->getGb28181Service()->stopLiveVideo($deviceId, $channelId, $streamId);

            return $snapshot;

        } catch (\Exception $e) {
            Log::channel('sip')->error('Capture after INVITE failed', [
                'device_id'  => $deviceId,
                'channel_id' => $channelId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function searchSnapshots(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20) : array
    {
        return $this->getSnapshotFileDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countSnapshots(array $conditions) : int
    {
        return $this->getSnapshotFileDao()->count($conditions);
    }

    public function getSnapshot(int $id) : ?array
    {
        return $this->getSnapshotFileDao()->get($id);
    }

    /**
     * 获取通道名称
     */
    private function getChannelName(string $deviceId, string $channelId) : ?string
    {
        $channel = $this->getDeviceService()->getChannel($deviceId, $channelId);
        return $channel['channel_name'] ?? null;
    }

    /**
     * 获取来源描述
     */
    private function getSourceDesc(string $sourceType, ?int $sourceId) : ?string
    {
        return match ($sourceType) {
            'alarm' => '报警事件',
            'plan' => '预案',
            'manual' => '手动抓拍',
            'playback' => '回放抓拍',
            default => null,
        };
    }

    /**
     * 从文件路径获取图片格式
     */
    private function getImageFormat(?string $filePath) : string
    {
        if (!$filePath) {
            return 'jpg';
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        return match ($ext) {
            'png', 'jpeg', 'webp' => $ext,
            default => 'jpg',
        };
    }

    /**
     * 生成资产ID（UUID）
     */
    private function generateAssetId() : string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    protected function getSnapshotFileDao() : SnapshotFileDao|DaoProxy
    {
        return $this->createDao('Snapshot:SnapshotFileDao');
    }

    protected function getDeviceService()
    {
        return $this->createService('Devices:DeviceService');
    }

    protected function getGb28181Service()
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }

    protected function getZlmClient()
    {
        return $this->bfw['zlm_sdk'];
    }
}
