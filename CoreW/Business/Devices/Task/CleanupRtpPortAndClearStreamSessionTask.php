<?php

namespace CoreW\Business\Devices\Task;


use CoreW\Business\Common\BaseCrontabTask;
use CoreW\Business\Devices\Enums\ChannelStreamStatus;
use CoreW\Business\Devices\Enums\StreamSessionType;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;

class CleanupRtpPortAndClearStreamSessionTask extends BaseCrontabTask
{
    private const COOLING_PERIOD = 360; // 单位：s（冷却期，无观众后保持流的最长时间）3600

    public function execute(): void
    {
        // 获取所有活跃的流会话
        $sessions = $this->getDeviceService()->searchSessions(
            [
                'no_type' => StreamSessionType::DOWNLOAD->value
            ],
            [],
            0,
            PHP_INT_MAX,
            ['id', 'stream_id', 'media_server_id', 'device_id', 'channel_id', 'type', 'viewer_count', 'last_reader_time', 'created_at']
        );

        $cleanupCount = 0;
        $skipCount = 0;

        foreach ($sessions as $session) {
            try {
                $result = $this->checkAndCleanupSession($session);
                if ($result) {
                    $cleanupCount++;
                } else {
                    $skipCount++;
                }
                usleep(100000);
            } catch (\Exception $e) {
                $this->log()->error('Cleanup session failed', [
                    'session_id' => $session['id'],
                    'stream_id' => $session['stream_id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->log()->info('Cleanup task completed', [
            'total' => count($sessions),
            'cleaned' => $cleanupCount,
            'skipped' => $skipCount
        ]);
    }

    /**
     * 检查并清理单个会话
     *
     * @param array $session
     * @return bool 是否清理了该会话
     */
    private function checkAndCleanupSession(array $session): bool
    {
        $streamId = $session['stream_id'];
        $mediaServerId = $session['media_server_id'];

        // 1. 获取 ZLM 中的流信息（以 ZLM 为准，不依赖 RTP 状态）
        try {
            $mediaList = $this->getGB28181Service()->getMediaList($mediaServerId, $streamId);

            // 流不存在于 ZLM，直接清理本地记录
            if (empty($mediaList)) {
//                $this->log()->info('ZLM 中找不到流，清理本地会话', [
//                    'stream_id' => $streamId
//                ]);
                $lastReaderTime = $session['last_reader_time'] ?? $session['created_at'];
                $idleSeconds = time() - strtotime($lastReaderTime);

                if ($idleSeconds < self::COOLING_PERIOD) {
                    return false;
                }

                return $this->cleanupSession($session, 'stream_not_found');
            }

            // 2. 检查观看人数（从 ZLM 的所有 schema 获取）
            $totalReaderCount = $this->countTotalReaderCount($mediaList);

            if ($totalReaderCount > 0) {
                // 有观众，更新本地 viewer_count（修正可能的不一致）
                $this->getDeviceService()->updateSession($session['id'], [
                    'player_count' => $totalReaderCount,
                    'last_reader_time' => date('Y-m-d H:i:s')
                ]);

//                $this->log()->debug('Stream has active readers, skip cleanup', [
//                    'stream_id' => $streamId,
//                    'reader_count' => $totalReaderCount
//                ]);
                return false;
            }

            // 3. 检查冷却期（无观众的持续时间）
            $lastReaderTime = $session['last_reader_time'] ?? $session['created_at'];
            $idleSeconds = time() - strtotime($lastReaderTime);

            if ($idleSeconds < self::COOLING_PERIOD) {
//                $this->log()->debug('Stream in cooling period, skip cleanup', [
//                    'stream_id' => $streamId,
//                    'idle_seconds' => $idleSeconds,
//                    'cooling_period' => self::COOLING_PERIOD
//                ]);
                return false;
            }

            // 4. 满足清理条件：无观众 + 超过冷却期
            $this->log()->info('Stream 无观众 + 超过冷却期', [
                'stream_id' => $streamId,
                'idle_seconds' => $idleSeconds,
                'type' => $session['type']
            ]);

            return $this->cleanupSession($session, 'idle_timeout');

        } catch (\Exception $e) {
            $this->log()->error('未能检查流媒体状态', [
                'stream_id' => $streamId,
                'error' => $e->getMessage()
            ]);

            // 如果 ZLM 查询失败超过一定时间，也清理（避免僵尸会话）
            $createdTime = strtotime($session['created_at']);
            if (time() - $createdTime > self::COOLING_PERIOD * 2) {
                $this->log()->warning('会话过旧且ZLM无法访问，强制清理', [
                    'stream_id' => $streamId
                ]);
                return $this->cleanupSession($session, 'force_cleanup');
            }

            return false;
        }
    }

    private function countTotalReaderCount(array $mediaList): int
    {
        return array_sum(array_column($mediaList, 'readerCount'));
    }

    /**
     * 执行清理动作
     *
     * @param array $session
     * @param string $reason 清理原因
     * @return bool
     */
    private function cleanupSession(array $session, string $reason): bool
    {
        $streamId = $session['stream_id'];
        $deviceId = $session['device_id'];
        $channelId = $session['channel_id'];
        $mediaServerId = $session['media_server_id'];
        $type = $session['type'];

        $this->log()->info('Start cleanup session', [
            'stream_id' => $streamId,
            'type' => $type,
            'reason' => $reason
        ]);

        try {
            // 1. 发送 BYE 到设备（停止推流）
            if ($type === StreamSessionType::LIVE->value) {
                $this->getGB28181Service()->stopLiveVideo($deviceId, $channelId, $streamId);
            } elseif ($type === StreamSessionType::PLAYBACK->value) {
                $this->getGB28181Service()->stopPlayback($deviceId, $channelId, $streamId);
            }

            // 2. 关闭 ZLM 的 RTP 端口
            $this->getGB28181Service()->releaseStreamSessionRtpPort($streamId, true);


            $this->log()->info('会话清理完成', [
                'stream_id' => $streamId,
                'type' => $type
            ]);

            return true;

        } catch (\Exception $e) {
            $this->log()->error('会话清理失败', [
                'stream_id' => $streamId,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }


    protected function getDeviceService(): DeviceService
    {
        return $this->getBfw()->service('Devices:DeviceService');
    }

    /**
     * @return Gb28181Service
     */
    protected function getGB28181Service(): Gb28181Service
    {
        return $this->getBfw()->offsetGet('gb28181_service');
    }

}