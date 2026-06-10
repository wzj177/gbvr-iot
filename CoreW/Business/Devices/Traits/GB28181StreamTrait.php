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
     * 开始实时视频核心逻辑
     *
     * 引用计数 + SIP 会话复用：N 个观看者共享同一个 ZLM RTP 端口和 SIP INVITE/BYE 生命周期。
     * 加 Redis 分布式锁解决并发双重创建（double-create）问题：
     *   fast-path → 无锁读已有会话（绝大多数调用）
     *   slow-path → 获锁 → 二次检查 → 创建会话（首次拉流或会话刚被释放）
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

        // Slow-path 前置校验（在锁外做，配置错误立即失败）
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

        // Slow-path：tryExec 非阻塞加锁，锁被占（stop 正在执行）时立即返回 null
        // 避免 start 死等 stop 释放锁导致 7-8 秒阻塞
        $result = $this->getLiveStreamLock()->tryExec(
            'live_stream:' . $channel['stream_id'],
            function () use ($channel, $mediaServer, $streamIp, $tcpMode, $device) {
                // 二次检查：锁内可能已被另一进程创建
                $activeSession = $this->getDeviceService()->getActiveSessionByStreamIdAndType(
                    $channel['stream_id'],
                    StreamSessionType::LIVE->value
                );
                if ($activeSession) {
                    $this->getGb28181Service()->incrementViewerCount($channel['stream_id']);
                    Log::channel('gb_stream')->info('Channel already streaming (after lock), reuse session', [
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
            },
            10
        );

        // tryExec 返回 null = 锁被占（stop 正在执行），提示前端稍后重试
        if ($result === null) {
            throw new \RuntimeException('流正在关闭中，请稍后重试', 503);
        }

        return $result;
    }

    /**
     * 停止实时视频核心逻辑
     *
     * 引用计数递减：与 startLiveVideoCore 对称，必须加同一把分布式锁。
     * 否则并发停止（多客户端同时关闭同一路流）会产生竞态：
     *   两个进程都读到 viewer_count=2 → 都走 else 递减 → count 归 0
     *   但 BYE 从未发送，RTP 端口和 SIP 会话泄漏，设备持续推流。
     * 锁内重新读取 viewer_count 后再判断，保证"读-改-发BYE"原子性。
     *
     * @param array $channel
     * @return void
     * @throws \Exception
     */
    protected function stopLiveVideoCore(array $channel) : bool
    {
        // 无 stream_id 或未关联媒体服务器，无需处理
        if (empty($channel['stream_id']) || $channel['media_server_id'] === MediaServerType::NONE->value) {
            return false;
        }

        // 与 start 使用同一把锁（live_stream:{stream_id}），串行化同一路流的增减
        return $this->getLiveStreamLock()->exec(
            'live_stream:' . $channel['stream_id'],
            function () use ($channel) {
                try {
                    // 锁内重新读取最新会话，避免使用锁外的陈旧 viewer_count
                    $activeSession = $this->getDeviceService()->getActiveSessionByStreamIdAndType(
                        $channel['stream_id'],
                        StreamSessionType::LIVE->value
                    );

                    // 最后一个观看者（或会话已不存在）：真正发 BYE + 关 RTP + 删会话
                    if (!$activeSession || (int)$activeSession['viewer_count'] <= 1) {
                        $gbResult = $this->getGb28181Service()->stopLiveVideo($channel['device_id'], $channel['channel_id'], $channel['stream_id']);
                        if ($gbResult) {
                            $result = $this->getGb28181Service()->closeRtpServer($channel['stream_id'], $channel['media_server_id']);

                            // 更新通道状态
                            $this->getDeviceService()->updateChannel($channel['id'], [
                                'stream_status' => ChannelStreamStatus::IDLE->value,
                                'updated_at'    => date('Y-m-d H:i:s'),
                            ]);

                            if ($activeSession) {
                                // 删除会话记录（viewer_count<=1 或会话残留）
                                $this->getDeviceService()->deleteSession($activeSession['id']);
                            }

                            if (($result['hit'] ?? 0) === 1) {
                                Log::channel('gb_stream')->info('Close RTP server has exist record');
                                return true;
                            }

                            Log::channel('gb_stream')->info('Close RTP server has no exist record');
                        }

                        return false;
                    }

                    // 还有其他观看者：仅递减计数
                    $this->getGb28181Service()->decrementViewerCount($channel['stream_id']);
                    Log::channel('gb_stream')->info('Stop live video: decrement viewer count', [
                        'stream_id'    => $channel['stream_id'],
                        'viewer_count' => (int)$activeSession['viewer_count'],
                    ]);
                    return true;

                } catch (\Exception $e) {
                    Log::channel('gb_stream')->warning('Close RTP server failed', [
                        'stream_id' => $channel['stream_id'],
                        'error'     => $e->getTraceAsString(),
                    ]);

                    return false;
                }
            },
            10
        );
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
     * 停止录像回放核心逻辑
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

        // 与 start 对称加锁，串行化同一路回放流的引用计数增减，避免并发停止泄漏
        return $this->getLiveStreamLock()->exec(
            'playback_stream:' . $streamId,
            function () use ($deviceId, $channelId, $streamId, $channel) {
                // 锁内重新读取最新会话
                $activeSession = $this->getDeviceService()->getActiveSessionByStreamIdAndType($streamId, StreamSessionType::PLAYBACK->value);
                if (!$activeSession || (int)$activeSession['viewer_count'] <= 1) {
                    $result = $this->getGb28181Service()->stopPlayback($deviceId, $channelId, $streamId);
                    if ($result) {
                        $this->getGb28181Service()->closeRtpServer($streamId, $channel['media_server_id']);
                    }

                    if ($activeSession) {
                        // 最后一个观看者或会话残留，删除会话
                        $this->getDeviceService()->deleteSession($activeSession['id']);
                    }

                    return $result;
                }

                // 还有其他观看者：仅递减
                $this->getGb28181Service()->decrementViewerCount($streamId);
                return true;
            },
            10
        );
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
