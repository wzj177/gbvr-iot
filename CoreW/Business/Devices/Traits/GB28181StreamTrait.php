<?php

namespace CoreW\Business\Devices\Traits;

use CoreW\Business\Devices\Enums\ChannelStreamStatus;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Enums\MediaServerType;
use CoreW\Business\Devices\Enums\StreamSessionType;
use CoreW\Business\Devices\Exception\DevicesException;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\MediaServer\Enums\ServerStatusEnum;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\Record\Service\RecordTaskService;
use support\Log;

/**
 * GB28181 视频流控制器共享逻辑
 */
trait GB28181StreamTrait
{
    /**
     * 验证时间格式
     */
    protected function validateTimeFormat(string $time) : bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $time) === 1;
    }

    /**
     * 验证设备和通道
     *
     * @return array ['device' => ..., 'channel' => ...] 或抛出异常
     * @throws \Exception
     */
    protected function validateDeviceAndChannel(string $deviceId, string $channelId) : array
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
            'device'  => $device,
            'channel' => $channel,
        ];
    }

    /**
     * 获取 TCP 模式
     */
    protected function getTcpMode(array $device) : int
    {
        // 优先使用设备的 rtp_trans_mode 配置
        return $device['rtp_trans_mode'] ?? 1; // 0=UDP, 1=TCP被动(推荐), 2=TCP主动
    }

    /**
     * 开始实时视频核心逻辑（乐观锁版本）
     *
     * 引用计数 + SIP 会话复用：N 个观看者共享同一个 ZLM RTP 端口和 SIP INVITE/BYE 生命周期。
     * 使用乐观锁替代悲观锁：
     *   fast-path → 无锁读已有会话（绝大多数调用）
     *   slow-path → 创建会话（利用唯一约束防重）
     *
     * @return array 返回会话信息
     * @throws \Exception
     */
    protected function startLiveVideoCore(array $device, array $channel) : array
    {
        // 检查通道是否关闭直播
        if (!empty($channel['close_live'])) {
            throw new \InvalidArgumentException('该通道已关闭直播功能', 403);
        }

        // Fast-path：会话已存在，验证 ZLM 流是否真的还活着，再决定复用还是重建
        //
        // 如果只查 DB session 就复用，可能 session 记录还在但 ZLM 流已经断了
        // （stop 正在路上、设备停推、ZLM 端口已关等），导致返回空流的 play_urls。
        // 加一步 ZLM getMediaList 检查，流不在了就清掉旧 session 走 slow-path 重建。
        $activeSession = $this->getDeviceService()->getActiveSessionByStreamIdAndType(
            $channel['stream_id'],
            StreamSessionType::LIVE->value
        );
        if ($activeSession) {
            // 检查 ZLM 流是否真的还活着
            $streamAlive = $this->isStreamAliveInZlm($channel);
            if ($streamAlive) {
                $this->getGb28181Service()->incrementViewerCount($channel['stream_id']);
                Log::channel('gb_stream')->info('Channel already streaming, reuse session', [
                    'stream_id'  => $channel['stream_id'],
                    'session_id' => $activeSession['id'],
                    'ssrc'       => $activeSession['ssrc'],
                ]);

                return [
                    'stream_id'         => $channel['stream_id'],
                    'ssrc'              => $activeSession['ssrc'],
                    'rtp_port'          => $activeSession['rtp_port'],
                    'tcp_mode'          => $activeSession['tcp_mode'],
                    'session_id'        => $activeSession['id'],
                    'already_streaming' => true,
                ];
            }

            // ZLM 流已断但 session 还在 → 清除僵尸 session，走 slow-path 重建
            Log::channel('gb_stream')->warning('Session exists but ZLM stream dead, cleaning up for rebuild', [
                'stream_id'  => $channel['stream_id'],
                'session_id' => $activeSession['id'],
            ]);
            try {
                $this->getDeviceService()->deleteSession($activeSession['id']);
                $this->getDeviceService()->updateChannel($channel['id'], [
                    'stream_status' => ChannelStreamStatus::IDLE->value,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $e) {
                Log::channel('gb_stream')->warning('Failed to clean dead session', [
                    'stream_id' => $channel['stream_id'],
                    'error'     => $e->getMessage(),
                ]);
            }
            // 继续走下面的 slow-path 重建流
        }

        // Slow-path 前置校验
        if ($channel['media_server_id'] === MediaServerType::NONE->value) {
            throw new \InvalidArgumentException('通道未关联媒体服务器', 400);
        }
        $mediaServer = $this->getMediaServerService()->getMediaServerByServerId($channel['media_server_id']);
        if (!$mediaServer) {
            throw new \InvalidArgumentException('媒体服务器不存在', 404);
        }
        if ($mediaServer['status'] !== ServerStatusEnum::RUNNING->value) {
            throw new \InvalidArgumentException('媒体服务器未运行', 503);
        }
        $streamIp = !empty($mediaServer['stream_ip']) ? $mediaServer['stream_ip'] : $mediaServer['host'];
        if (empty($streamIp)) {
            throw new \InvalidArgumentException('媒体服务器缺少收流IP配置', 500);
        }
        $tcpMode = $this->getTcpMode($device);
        if ($mediaServer['type'] === MediaServerType::SRS->value) {
            $tcpMode = 1;
        }

        // Slow-path：创建直播会话（无锁，依赖唯一约束防重）
        return $this->createLiveSessionWithRetry($channel, $mediaServer, $streamIp, $tcpMode, $device, 0);
    }

    /**
     * 创建直播会话（带重试机制处理并发冲突）
     *
     * @param array $channel
     * @param array $mediaServer
     * @param string $streamIp
     * @param int $tcpMode
     * @param array $device
     * @param int $retryCount 重试次数
     * @return array
     * @throws \Exception
     */
    private function createLiveSessionWithRetry(
        array $channel,
        array $mediaServer,
        string $streamIp,
        int $tcpMode,
        array $device,
        int $retryCount
    ) : array {
        // 防止无限重试
        if ($retryCount > 2) {
            throw new \RuntimeException('创建直播会话失败：并发冲突过多', 503);
        }

        // 二次检查：可能已被另一进程创建
        $activeSession = $this->getDeviceService()->getActiveSessionByStreamIdAndType(
            $channel['stream_id'],
            StreamSessionType::LIVE->value
        );
        if ($activeSession) {
            $this->getGb28181Service()->incrementViewerCount($channel['stream_id']);
            Log::channel('gb_stream')->info('Channel already streaming (race detected), reuse session', [
                'stream_id'  => $channel['stream_id'],
                'session_id' => $activeSession['id'],
                'ssrc'       => $activeSession['ssrc'],
            ]);

            return [
                'stream_id'         => $channel['stream_id'],
                'ssrc'              => $activeSession['ssrc'],
                'rtp_port'          => $activeSession['rtp_port'],
                'tcp_mode'          => $activeSession['tcp_mode'],
                'session_id'        => $activeSession['id'],
                'already_streaming' => true,
            ];
        }

        // 创建直播会话
        $sessionResult = $this->getGb28181Service()->createLiveSessionAndOpenRtp($channel, $mediaServer, $tcpMode);
        if (!$sessionResult) {
            throw new \RuntimeException('创建直播会话失败', 500);
        }

        $zlmPort = $sessionResult['rtp_port'];
        $ssrc = $sessionResult['ssrc'];
        $streamId = $sessionResult['stream_id'];

        // 插入 session 记录（利用唯一约束防重）
        try {
            $this->getDeviceService()->createSession([
                'stream_id'         => $streamId,
                'type'               => StreamSessionType::LIVE->value,
                'device_id'          => $channel['device_id'],
                'channel_id'         => $channel['channel_id'],
                'media_server_id'    => $channel['media_server_id'],
                'ssrc'               => $ssrc,
                'rtp_port'           => $zlmPort,
                'tcp_mode'           => $tcpMode,
                'viewer_count'       => 1,
                'status'             => 'inviting',
                'created_at'         => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            // 并发冲突：另一个进程已经创建了 session
            // 清理自己创建的 RTP，重试
            Log::channel('gb_stream')->warning('Session create race detected, cleaning up and retry', [
                'stream_id' => $streamId,
                'retry'     => $retryCount + 1,
            ]);
            $this->getGb28181Service()->closeRtpServer($streamId, $mediaServer['server_id']);
            usleep(50000); // 等待 50ms
            return $this->createLiveSessionWithRetry($channel, $mediaServer, $streamIp, $tcpMode, $device, $retryCount + 1);
        } catch (\Exception $e) {
            // 其他错误，清理 RTP
            $this->getGb28181Service()->closeRtpServer($streamId, $mediaServer['server_id']);
            throw new \RuntimeException('创建 session 失败: ' . $e->getMessage(), 500);
        }

        // 发送命令到信令网关（传递收流IP）
        $result = $this->getGb28181Service()->startLiveVideo(
            $channel,
            $zlmPort,
            $tcpMode,
            $streamId,
            $streamIp,
            $device['senior_sdp'] ?? false
        );
        if (!$result) {
            $this->getGb28181Service()->closeRtpServer($streamId, $mediaServer['server_id']);
            $this->getDeviceService()->deleteSessionByStreamIdAndMediaServerId($streamId, $mediaServer['server_id']);
            throw new \RuntimeException('发送实时视频请求失败', 500);
        }

        Log::channel('gb_stream')->info('Start live video command sent', [
            'channel'   => $channel,
            'ssrc'      => $ssrc,
            'rtp_port'  => $zlmPort,
            'tcp_mode'  => $tcpMode,
            'stream_id' => $streamId,
        ]);

        return [
            'stream_id' => $streamId,
            'ssrc'      => $ssrc,
            'rtp_port'  => $zlmPort,
            'tcp_mode'  => $tcpMode,
        ];
    }

    /**
     * 停止实时视频核心逻辑（乐观锁版本）
     *
     * 使用 CAS（Compare-And-Set）更新 viewer_count，替代悲观锁：
     *   1. 先尝试 CAS 递减 viewer_count（仅当 > 1 时成功）
     *   2. CAS 成功 → 还有其他观看者，直接返回
     *   3. CAS 失败 → viewer_count <= 1，真正关闭会话
     *
     * 优点：无阻塞，高并发，无需 Redis 锁
     *
     * @param array $channel
     * @return bool
     * @throws \Exception
     */
    protected function stopLiveVideoCore(array $channel) : bool
    {
        // 无 stream_id 或未关联媒体服务器，无需处理
        if (empty($channel['stream_id']) || $channel['media_server_id'] === MediaServerType::NONE->value) {
            return false;
        }

        // 乐观锁：先尝试 CAS 递减 viewer_count
        $result = $this->getDeviceService()->casDecrementSessionViewerCount(
            $channel['stream_id'],
            StreamSessionType::LIVE->value
        );

        switch ($result['action']) {
            case 'decremented':
                // CAS 成功：还有其他观看者，仅递减
                Log::channel('gb_stream')->info('Stop live video: decremented viewer count (optimistic lock)', [
                    'stream_id' => $channel['stream_id'],
                ]);
                return true;

            case 'not_found':
                // 会话不存在，无需处理
                Log::channel('gb_stream')->info('Stop live video: session not found', [
                    'stream_id' => $channel['stream_id'],
                ]);
                return false;

            case 'retry':
                // 并发冲突：另一个进程正在处理，短暂等待后重试
                usleep(50000); // 等待 50ms
                $result = $this->getDeviceService()->casDecrementSessionViewerCount(
                    $channel['stream_id'],
                    StreamSessionType::LIVE->value
                );
                // 重试后仍失败，当作 closed 处理
                if ($result['action'] === 'decremented') {
                    return true;
                }
                // 继续走 closed 逻辑
                break;

            case 'closed':
                // CAS 失败：viewer_count <= 1，需要真正关闭
                break;
        }

        // 真正关闭会话（最后一个观看者或会话残留）
        try {
            $gbResult = $this->getGb28181Service()->stopLiveVideo(
                $channel['device_id'],
                $channel['channel_id'],
                $channel['stream_id']
            );

            if ($gbResult) {
                $closeResult = $this->getGb28181Service()->closeRtpServer(
                    $channel['stream_id'],
                    $channel['media_server_id']
                );

                // 更新通道状态
                $this->getDeviceService()->updateChannel($channel['id'], [
                    'stream_status' => ChannelStreamStatus::IDLE->value,
                    'updated_at'    => date('Y-m-d H:i:s'),
                ]);

                // 删除会话记录
                if (isset($result['session'])) {
                    $this->getDeviceService()->deleteSession($result['session']['id']);
                } else {
                    // 二次查找确保删除
                    $session = $this->getDeviceService()->getActiveSessionByStreamIdAndType(
                        $channel['stream_id'],
                        StreamSessionType::LIVE->value
                    );
                    if ($session) {
                        $this->getDeviceService()->deleteSession($session['id']);
                    }
                }

                if (($closeResult['hit'] ?? 0) === 1) {
                    Log::channel('gb_stream')->info('Close RTP server: has exist record');
                } else {
                    Log::channel('gb_stream')->info('Close RTP server: no exist record');
                }

                return true;
            }

            return false;
        } catch (\Exception $e) {
            Log::channel('gb_stream')->warning('Close RTP server failed', [
                'stream_id' => $channel['stream_id'],
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }


    /**
     * 开始录像回放核心逻辑
     *
     * @param array $device
     * @param array $channel
     * @param string $startTime
     * @param string $endTime
     * @return array
     */
    protected function startPlaybackCore(array $device, array $channel, string $startTime, string $endTime) : array
    {
        if ($channel['status'] !== DeviceStatusEnum::ONLINE->value) {
            throw new \InvalidArgumentException('通道未在线', 400);
        }

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
        $tcpMode = $this->getTcpMode($device);
        $sessionResult = $this->getGb28181Service()->createPlaybackSessionAndOpenRtp(
            $channel['device_id'],
            $channel['channel_id'],
            $startTime,
            $endTime,
            $mediaServer,
            $tcpMode
        );

        if (!$sessionResult) {
            throw new \RuntimeException('创建回放会话失败', 500);
        }

        $playbackStreamId = $sessionResult['stream_id'];
        $playbackSsrc = $sessionResult['ssrc'];
        $zlmPort = $sessionResult['rtp_port'];

        // 发送命令到信令网关（传递收流IP）
        $result = $this->getGb28181Service()->startPlayback(
            $channel,
            $startTime,
            $endTime,
            $playbackSsrc,
            $zlmPort,
            $tcpMode,
            $playbackStreamId,
            $streamIp  // 传递收流IP到信令网关
        );

        if (!$result) {
            $this->getGb28181Service()->closeRtpServer($playbackStreamId, $channel['media_server_id']);
            throw new \RuntimeException('发送回放请求失败', 500);
        }

        Log::channel('gb_stream')->info('Start playback command sent', [
            'device_id'  => $channel['device_id'],
            'channel_id' => $channel['channel_id'],
            'start_time' => $startTime,
            'end_time'   => $endTime,
        ]);

        return [
            'stream_id' => $playbackStreamId,
            'ssrc'      => $playbackSsrc,
            'rtp_port'  => $zlmPort,
        ];
    }

    /**
     * 停止录像回放核心逻辑（乐观锁版本）
     *
     * @param string $deviceId
     * @param string $channelId
     * @param string $streamId
     * @return bool
     */
    protected function stopPlaybackCore(string $deviceId, string $channelId, string $streamId) : bool
    {
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);
        if (!$device) {
            throw new \InvalidArgumentException('设备不存在', 404);
        }

        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);
        if (!$channel) {
            throw new \InvalidArgumentException('通道不存在', 404);
        }

        if ($channel['status'] !== DeviceStatusEnum::ONLINE->value) {
            throw new \InvalidArgumentException('通道未在线', 400);
        }

        if ($channel['media_server_id'] === MediaServerType::NONE->value) {
            throw new \InvalidArgumentException('通道未关联媒体服务器', 400);
        }

        // 乐观锁：先尝试 CAS 递减 viewer_count
        $result = $this->getDeviceService()->casDecrementSessionViewerCount(
            $streamId,
            StreamSessionType::PLAYBACK->value
        );

        switch ($result['action']) {
            case 'decremented':
                // CAS 成功：还有其他观看者，仅递减
                Log::channel('gb_stream')->info('Stop playback: decremented viewer count (optimistic lock)', [
                    'stream_id' => $streamId,
                ]);
                return true;

            case 'not_found':
                // 会话不存在，无需处理
                return false;

            case 'retry':
                // 并发冲突，短暂等待后重试
                usleep(50000);
                $result = $this->getDeviceService()->casDecrementSessionViewerCount(
                    $streamId,
                    StreamSessionType::PLAYBACK->value
                );
                if ($result['action'] === 'decremented') {
                    return true;
                }
                break;

            case 'closed':
                // CAS 失败：需要真正关闭
                break;
        }

        // 真正关闭会话
        $stopResult = $this->getGb28181Service()->stopPlayback($deviceId, $channelId, $streamId);
        if ($stopResult) {
            $this->getGb28181Service()->closeRtpServer($streamId, $channel['media_server_id']);
        }

        // 删除会话
        if (isset($result['session'])) {
            $this->getDeviceService()->deleteSession($result['session']['id']);
        } else {
            $session = $this->getDeviceService()->getActiveSessionByStreamIdAndType(
                $streamId,
                StreamSessionType::PLAYBACK->value
            );
            if ($session) {
                $this->getDeviceService()->deleteSession($session['id']);
            }
        }

        return $stopResult;
    }

    /**
     * 获取播放地址
     *
     * @param array $channel 通道
     * @param string|null $streamId
     * @param string|null $mediaServerId
     * @return array
     */
    protected function getPlayUrlsCore(array $channel, ?string $streamId = null, ?string $mediaServerId = null) : array
    {
        try {
            $accessDomain = null;
            if (!$streamId) {
                $streamId = $channel['stream_id'];
            }

            if (!$mediaServerId) {
                $mediaServerId = $channel['media_server_id'];
            }

            // 如果提供了媒体服务器ID，获取其访问地址
            if ($mediaServerId && $mediaServerId !== MediaServerType::NONE->value) {
                $mediaServer = $this->getMediaServerService()->getMediaServerByServerId($mediaServerId);
                if ($mediaServer && !empty($mediaServer['access_domain'])) {
                    $accessDomain = $mediaServer['access_domain'];
                }
            }

            return $this->getGb28181Service()->getPlayUrls($mediaServerId, $streamId, $accessDomain);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 录像回放控制核心逻辑
     *
     * 支持的操作:
     * - play: 正常播放
     * - pause: 暂停
     * - fast_forward: 快进 (speed: 2=2倍速, 4=4倍速)
     * - slow_forward: 慢放 (speed: 1=0.5倍速, 2=0.25倍速)
     * - seek: 拖动到指定时间
     * - scale: 缩放
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $streamId 流ID（用于标识活跃会话）
     * @param string $action 操作类型
     * @param int|float $speed 倍速（用于快进/慢放）
     * @param string|null $seekTime 拖动时间（ISO8601格式，用于seek操作）
     * @param float $scale 缩放比例（用于scale操作）
     * @return array 返回执行结果
     * @throws \Exception
     */
    protected function playbackControlCore(
        string $deviceId,
        string $channelId,
        string $streamId,
        string $action,
        int|float $speed = 1,
        ?string $seekTime = null,
        float $scale = 1.0
    ) : array
    {
        Log::info('Playback control request', [
            'device_id'  => $deviceId,
            'channel_id' => $channelId,
            'stream_id'  => $streamId,
            'action'     => $action,
            'speed'      => $speed,
            'seek_time'  => $seekTime,
            'scale'      => $scale,
        ]);

        // 推送命令到 Redis 队列，由 Gateway 处理
        $result = $this->getGb28181Service()->sendPlaybackControl(
            $deviceId,
            $channelId,
            $streamId,
            $action,
            $speed,
            $seekTime,
            $scale
        );

        if (!$result) {
            throw new \RuntimeException('发送回放控制命令失败', 500);
        }

        return [
            'success' => true,
            'action'  => $action,
            'speed'   => $speed,
        ];
    }

    /**
     * 录像下载核心逻辑
     *
     * 与普通回放的区别：
     * - session_name = 'Download' (而非 'Playback')
     * - 媒体服务器会将流录制为文件
     * - 返回文件下载地址
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $startTime 开始时间（ISO8601格式）
     * @param string $endTime 结束时间（ISO8601格式）
     * @param int $downloadSpeed 下载倍速（1-4）
     * @return array 返回下载会话信息
     * @throws \Exception
     */
    protected function downloadRecordCore(
        string $deviceId,
        string $channelId,
        string $startTime,
        string $endTime,
        int $downloadSpeed = 1
    ) : array
    {
        // 验证设备和通道
        ['device' => $device, 'channel' => $channel] = $this->validateDeviceAndChannel($deviceId, $channelId);

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
        if ($mediaServer['status'] !== ServerStatusEnum::RUNNING->value) {
            throw new \InvalidArgumentException('媒体服务器未运行', 503);
        }

        // 获取收流IP
        $streamIp = !empty($mediaServer['stream_ip']) ? $mediaServer['stream_ip'] : $mediaServer['host'];
        if (empty($streamIp)) {
            throw new \InvalidArgumentException('媒体服务器缺少收流IP配置', 500);
        }

        // 创建下载会话
        $tcpMode = $this->getTcpMode($device);
        $sessionResult = $this->getGb28181Service()->createDownloadSessionAndOpenRtp(
            $deviceId,
            $channelId,
            $startTime,
            $endTime,
            $mediaServer,
            $tcpMode,
            $downloadSpeed
        );

        if (!$sessionResult) {
            throw new \RuntimeException('创建下载会话失败', 500);
        }

        $downloadStreamId = $sessionResult['stream_id'];
        $downloadSsrc = $sessionResult['ssrc'];
        $zlmPort = $sessionResult['rtp_port'];

        // 发送 INVITE 到信令网关（session_name = Download）
        $result = $this->getGb28181Service()->startDownload(
            $channel,
            $startTime,
            $endTime,
            $downloadSsrc,
            $zlmPort,
            $tcpMode,
            $downloadStreamId,
            $streamIp,
            $downloadSpeed
        );

        if (!$result) {
            $this->getGb28181Service()->closeRtpServer($downloadStreamId, $channel['media_server_id']);
            throw new \RuntimeException('发送下载请求失败', 500);
        }

        // 创建录像任务
        $recordTask = $this->getRecordTaskService()->createDownloadRecordTask(
            $deviceId,
            $channelId,
            $startTime,
            $endTime,
            $downloadStreamId,
            $downloadSsrc,
            $downloadSpeed
        );

        Log::channel('gb_stream')->info('Start download command sent', [
            'device_id'      => $deviceId,
            'channel_id'     => $channelId,
            'start_time'     => $startTime,
            'end_time'       => $endTime,
            'download_speed' => $downloadSpeed,
            'task_id'        => $recordTask['id'],
        ]);

        return [
            'stream_id'      => $downloadStreamId,
            'ssrc'           => $downloadSsrc,
            'rtp_port'       => $zlmPort,
            'download_speed' => $downloadSpeed,
            'task_id'        => $recordTask['id'],
            'download_url'   => null,  // 由 ZLM 录制完成后提供
            'file_size'      => 0,
        ];
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
    abstract protected function getGb28181Service() : Gb28181Service;

    /**
     * @return DeviceService
     */
    abstract protected function getDeviceService() : DeviceService;

    /**
     * @return RecordTaskService
     */
    abstract protected function getRecordTaskService() : RecordTaskService;


    protected function getMediaServerService() : MediaServerService
    {
        return $this->createService('MediaServer:MediaServerService');
    }

    protected function getLiveStreamLock() : \CoreW\Business\Lock\RedisLock
    {
        return \CoreW\Core::instance()->offsetGet('lock.redis');
    }

    /**
     * 检查 ZLM 上流是否真的还活着
     *
     * 通过 getMediaList 查询 ZLM，确认 rtp/{streamId} 流是否存在。
     * 返回 true = 流在，可以复用 session；false = 流断了，需要重建。
     *
     * @param array $channel 通道信息（需要 media_server_id 和 stream_id）
     */
    protected function isStreamAliveInZlm(array $channel) : bool
    {
        $mediaServerId = $channel['media_server_id'] ?? '';
        $streamId = $channel['stream_id'] ?? '';

        if (!$mediaServerId || $mediaServerId === MediaServerType::NONE->value || !$streamId) {
            return false;
        }

        try {
            $mediaList = $this->getGb28181Service()->getMediaList($mediaServerId, $streamId, 'rtp');
            // getMediaList 返回非空数组 = 流存在于 ZLM
            return !empty($mediaList);
        } catch (\Exception $e) {
            // ZLM 查询失败（网络不通、ZLM 挂了），保守返回 false，触发重建
            Log::channel('gb_stream')->warning('ZLM media list check failed', [
                'stream_id' => $streamId,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }
}
