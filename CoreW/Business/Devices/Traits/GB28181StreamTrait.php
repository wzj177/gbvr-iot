<?php

namespace CoreW\Business\Devices\Traits;

use CoreW\Business\Devices\Enums\MediaServerType;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use support\Log;

/**
 * GB28181 视频流控制器共享逻辑
 */
trait GB28181StreamTrait
{
    /**
     * 验证时间格式
     */
    protected function validateTimeFormat(string $time): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $time) === 1;
    }

    /**
     * 验证设备和通道
     *
     * @return array ['device' => ..., 'channel' => ...] 或抛出异常
     * @throws \Exception
     */
    protected function validateDeviceAndChannel(string $deviceId, string $channelId): array
    {
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);

        if (!$device || !$device['enabled']) {
            throw new \InvalidArgumentException('设备不存在', 404);
        }

        if ($device['status'] !== 'online') {
            throw new \InvalidArgumentException('设备离线', 400);
        }

        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);

        if (!$channel) {
            throw new \InvalidArgumentException('通道不存在', 404);
        }

        return [
            'device' => $device,
            'channel' => $channel,
        ];
    }

    /**
     * 获取 TCP 模式
     */
    protected function getTcpMode(array $device, array $channel): int
    {
        // 优先使用设备的 rtp_trans_mode 配置
        return $device['rtp_trans_mode'] ?? 1; // 0=UDP, 1=TCP被动(推荐), 2=TCP主动
    }

    /**
     * 开始实时视频核心逻辑
     *
     * @return array 返回会话信息
     * @throws \Exception
     */
    protected function startLiveVideoCore(string $deviceId, string $channelId, array $device, array $channel): array
    {
        // 检查媒体服务器
        if ($channel['media_server_id'] === MediaServerType::NONE->value) {
            throw new \InvalidArgumentException('通道未关联媒体服务器', 400);
        }

        // 获取媒体服务器信息
        $mediaServer = $this->getMediaServerService()->getMediaServerByServerId($channel['media_server_id']);
        if (!$mediaServer) {
            throw new \InvalidArgumentException('媒体服务器不存在', 404);
        }

        // 检查媒体服务器状态
        if ($mediaServer['status'] !== 'running') {
            throw new \InvalidArgumentException('媒体服务器未运行', 503);
        }

        // 获取收流IP（优先使用stream_ip，否则使用host）
        $streamIp = !empty($mediaServer['stream_ip']) ? $mediaServer['stream_ip'] : $mediaServer['host'];
        if (empty($streamIp)) {
            throw new \InvalidArgumentException('媒体服务器缺少收流IP配置', 500);
        }

        // 获取 TCP 模式
        $tcpMode = $this->getTcpMode($device, $channel);

        // 创建直播会话
        $sessionResult = $this->getGb28181Service()->createLiveSession(
            $deviceId,
            $channelId,
            $tcpMode
        );

        if (!$sessionResult) {
            throw new \RuntimeException('创建直播会话失败', 500);
        }

        $zlmPort = $sessionResult['zlm_port'];
        $ssrc = $sessionResult['ssrc'];
        $streamId = $sessionResult['stream_id'];

        // 发送命令到信令网关（传递收流IP）
        $result = $this->getGb28181Service()->startLiveVideo(
            $deviceId,
            $channelId,
            $ssrc,
            $zlmPort,
            $tcpMode,
            $streamId,
            $streamIp  // 传递收流IP到信令网关
        );

        if (!$result) {
            $this->getGb28181Service()->closeRtpServer($streamId);
            throw new \RuntimeException('发送实时视频请求失败', 500);
        }

        // 更新通道状态
        $this->getDeviceService()->updateChannel($channel['id'], [
            'status' => 'streaming',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Log::channel('sip')->info('Start live video command sent', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'ssrc' => $ssrc,
            'zlm_port' => $zlmPort,
            'tcp_mode' => $tcpMode,
            'stream_id' => $streamId,
        ]);

        return [
            'stream_id' => $streamId,
            'ssrc' => $ssrc,
            'zlm_port' => $zlmPort,
            'tcp_mode' => $tcpMode,
        ];
    }

    /**
     * 停止实时视频核心逻辑
     *
     * @return void
     * @throws \Exception
     */
    protected function stopLiveVideoCore(string $deviceId, string $channelId, array $channel): void
    {
        // 发送BYE到信令网关
        $result = $this->getGb28181Service()->stopLiveVideo($deviceId, $channelId);

        if (!$result) {
            throw new \RuntimeException('发送停止实时视频请求失败', 500);
        }

        // 关闭ZLM端口
        if (isset($channel['stream_id']) && $channel['stream_id']) {
            try {
                $this->getGb28181Service()->closeRtpServer($channel['stream_id']);
            } catch (\Exception $e) {
                Log::channel('sip')->warning('Close RTP server failed', [
                    'stream_id' => $channel['stream_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 更新通道状态
        $this->getDeviceService()->updateChannel($channel['id'], [
            'status' => 'offline',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        Log::channel('sip')->info('Stop live video command sent', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
        ]);
    }

    /**
     * 开始录像回放核心逻辑
     *
     * @return array 返回会话信息
     * @throws \Exception
     */
    protected function startPlaybackCore(string $deviceId, string $channelId, string $startTime, string $endTime, array $device, array $channel): array
    {
        // 检查媒体服务器
        if ($channel['media_server_id'] === MediaServerType::NONE->value) {
            throw new \InvalidArgumentException('通道未关联媒体服务器', 400);
        }

        // 获取媒体服务器信息
        $mediaServer = $this->getMediaServerService()->getMediaServerByServerId($channel['media_server_id']);
        if (!$mediaServer) {
            throw new \InvalidArgumentException('媒体服务器不存在', 404);
        }

        // 检查媒体服务器状态
        if ($mediaServer['status'] !== 'running') {
            throw new \InvalidArgumentException('媒体服务器未运行', 503);
        }

        // 获取收流IP
        $streamIp = !empty($mediaServer['stream_ip']) ? $mediaServer['stream_ip'] : $mediaServer['host'];
        if (empty($streamIp)) {
            throw new \InvalidArgumentException('媒体服务器缺少收流IP配置', 500);
        }

        // 创建回放会话
        $tcpMode = $this->getTcpMode($device, $channel);

        $sessionResult = $this->getGb28181Service()->createPlaybackSession(
            $deviceId,
            $channelId,
            $startTime,
            $endTime,
            $tcpMode
        );

        if (!$sessionResult) {
            throw new \RuntimeException('创建回放会话失败', 500);
        }

        $playbackStreamId = $sessionResult['stream_id'];
        $playbackSsrc = $sessionResult['ssrc'];
        $zlmPort = $sessionResult['zlm_port'];

        // 发送命令到信令网关（传递收流IP）
        $result = $this->getGb28181Service()->startPlayback(
            $deviceId,
            $channelId,
            $startTime,
            $endTime,
            $playbackSsrc,
            $zlmPort,
            $tcpMode,
            $playbackStreamId,
            $streamIp  // 传递收流IP到信令网关
        );

        if (!$result) {
            $this->getGb28181Service()->closeRtpServer($playbackStreamId);
            throw new \RuntimeException('发送回放请求失败', 500);
        }

        Log::channel('sip')->info('Start playback command sent', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);

        return [
            'stream_id' => $playbackStreamId,
            'ssrc' => $playbackSsrc,
            'zlm_port' => $zlmPort,
        ];
    }

    /**
     * 获取播放地址
     *
     * @param string $streamId 流ID
     * @param string|null $mediaServerId 媒体服务器ID
     * @return array
     */
    protected function getPlayUrlsCore(string $streamId, ?string $mediaServerId = null): array
    {
        try {
            $accessUrl = null;

            // 如果提供了媒体服务器ID，获取其访问地址
            if ($mediaServerId && $mediaServerId !== MediaServerType::NONE->value) {
                $mediaServer = $this->getMediaServerService()->getMediaServerByServerId($mediaServerId);
                if ($mediaServer && !empty($mediaServer['access_url'])) {
                    $accessUrl = $mediaServer['access_url'];
                }
            }

            return $this->getGb28181Service()->getPlayUrls('rtp', $streamId, $accessUrl);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 处理异常并返回错误响应
     *
     * @param \Exception $e
     * @return mixed
     */
    abstract protected function handleStreamException(\Exception $e);

    /**
     * @return Gb28181Service
     */
    abstract protected function getGb28181Service(): Gb28181Service;

    /**
     * @return DeviceService
     */
    abstract protected function getDeviceService(): DeviceService;

    /**
     * @return \CoreW\Business\Service\MediaServerService
     */
    abstract protected function getMediaServerService(): \CoreW\Business\Service\MediaServerService;
}
