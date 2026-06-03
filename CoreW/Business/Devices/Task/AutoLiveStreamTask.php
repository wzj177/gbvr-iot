<?php

namespace CoreW\Business\Devices\Task;

use CoreW\Business\Common\BaseCrontabTask;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Enums\StreamSessionType;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Traits\GB28181StreamTrait;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\Record\Service\RecordTaskService;

/**
 * 自动直播流管理任务
 *
 * 遍历所有 auto_live=1 的视频通道：
 * - 通道在线 + 无活跃流 → 通过 trait 的 startLiveVideoCore 发起 SIP INVITE 启流
 * - 通道离线/auto_live 被关闭 + 有活跃流 → 通过 trait 的 stopLiveVideoCore 发 SIP BYE 停流
 */
class AutoLiveStreamTask extends BaseCrontabTask
{
    use GB28181StreamTrait;

    public function execute() : void
    {
        // 1. 处理需要启流的通道（auto_live=1 且在线）
        $this->processAutoStartChannels();

        // 2. 处理需要停流的通道（auto_live 被关闭但仍有自动启流的会话残留）
        $this->processAutoStopChannels();
    }

    /**
     * 处理需要自动启流的通道
     */
    private function processAutoStartChannels() : void
    {
        $channels = $this->getDeviceService()->getAutoLiveChannels();

        $startCount = 0;
        $skipCount = 0;
        $failCount = 0;

        foreach ($channels as $channel) {
            try {
                // 检查是否已有活跃的直播会话
                // 注意：getAutoLiveChannels() 已过滤 status=online，无需在此重复判断离线
                $activeSession = $this->getDeviceService()->getActiveSessionByStreamIdAndType(
                    $channel['stream_id'],
                    StreamSessionType::LIVE->value
                );

                if ($activeSession) {
                    $skipCount++;
                    continue;
                }

                // 获取设备信息
                $device = $this->getDeviceService()->getDeviceByDeviceId($channel['device_id']);
                if (!$device || !$device['enabled'] || $device['status'] !== DeviceStatusEnum::ONLINE->value) {
                    $skipCount++;
                    continue;
                }

                // 复用 trait 的 startLiveVideoCore（内含 close_live 检查）
                $result = $this->startLiveVideoCore($device, $channel);

                $this->log()->info('[AutoLive] 自动启流成功', [
                    'device_id'  => $channel['device_id'],
                    'channel_id' => $channel['channel_id'],
                    'stream_id'  => $result['stream_id'],
                ]);
                $startCount++;

                // 每个通道之间间隔200ms，避免瞬时压力过大
                usleep(200000);
            } catch (\Throwable $e) {
                $failCount++;
                $this->log()->error('[AutoLive] 启流异常', [
                    'channel_id' => $channel['channel_id'] ?? '',
                    'device_id'  => $channel['device_id'] ?? '',
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if ($startCount > 0 || $failCount > 0) {
            $this->log()->info('[AutoLive] 启流统计', [
                'total'   => count($channels),
                'started' => $startCount,
                'skipped' => $skipCount,
                'failed'  => $failCount,
            ]);
        }
    }

    /**
     * 处理需要停流的通道
     *
     * 注意：不再在这里主动停流，而是依赖 CleanupRtpPortAndClearStreamSessionTask 的超时清理机制。
     * CleanupRtpPortAndClearStreamSessionTask 会检查 ZLM 中的实际观看人数，更加准确。
     *
     * 如果需要立即停止 auto_live 关闭的流，可以在这里检查 ZLM 的 readerCount，
     * 但目前保持简单，让清理任务统一处理。
     */
    private function processAutoStopChannels() : void
    {
        // 已由 CleanupRtpPortAndClearStreamSessionTask 统一处理
        // 不再需要单独的 auto_live 停流逻辑
    }

    /**
     * 尝试停止通道的直播流（复用 trait 的 stopLiveVideoCore）
     */
    private function tryStopChannel(array $channel, string $reason) : void
    {
        try {
            $result = $this->stopLiveVideoCore($channel);

//            if ($result) {
//                $this->log()->info('[AutoLive] 自动停流', [
//                    'device_id'  => $channel['device_id'],
//                    'channel_id' => $channel['channel_id'],
//                    'stream_id'  => $channel['stream_id'],
//                    'reason'     => $reason,
//                ]);
//            }
        } catch (\Throwable $e) {
            $this->log()->error('[AutoLive] 停流失败', [
                'stream_id' => $channel['stream_id'] ?? '',
                'reason'    => $reason,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ==================== Trait 抽象方法实现 ====================

    protected function handleStreamException(\Exception $e)
    {
        $this->log()->error('[AutoLive] Stream exception: ' . $e->getMessage());
    }

    protected function getGb28181Service() : Gb28181Service
    {
        return $this->getBfw()->offsetGet('gb28181_service');
    }

    protected function getDeviceService() : DeviceService
    {
        return $this->getBfw()->service('Devices:DeviceService');
    }

    protected function getRecordTaskService() : RecordTaskService
    {
        return $this->getBfw()->service('Record:RecordTaskService');
    }

    protected function getMediaServerService() : MediaServerService
    {
        return $this->getBfw()->service('MediaServer:MediaServerService');
    }
}
