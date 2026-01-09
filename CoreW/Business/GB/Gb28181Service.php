<?php

namespace CoreW\Business\GB;

use CoreW\Bfw;
use CoreW\Business\Devices\Enums\MediaServerType;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Exception\ZlmException;
use CoreW\Sdk\PSipGateway\Gb28181Client;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use CoreW\Utils\CRC32Helper;

class Gb28181Service
{
    public function __construct(protected Bfw $bfw)
    {
    }

    public function startLiveVideo(
        string $deviceId,
        string $channelId,
        string $ssrc,
        int $zlmPort,
        int $tcpMode = 1,
        ?string $streamId = null,
        ?string $streamIp = null
    ): bool {
        $this->checkZlmState($streamId);
        return $this->getGb28181Client()->startLiveVideo($deviceId, $channelId, $ssrc, $zlmPort, $tcpMode, $streamId, $streamIp);
    }

    public function stopLiveVideo(string $deviceId, string $channelId): bool
    {
        return $this->getGb28181Client()->stopLiveVideo($deviceId, $channelId);
    }

    public function startPlayback(
        string $deviceId,
        string $channelId,
        string $startTime,
        string $endTime,
        string $ssrc,
        int $zlmPort,
        int $tcpMode = 1,
        ?string $streamId = null,
        ?string $streamIp = null
    ): bool {
        $this->checkZlmState();
        return $this->getGb28181Client()->startPlayback($deviceId, $channelId, $startTime, $endTime, $ssrc, $zlmPort, $tcpMode, $streamId, $streamIp);
    }

    public function stopPlayback(string $deviceId, string $channelId): bool
    {
        return $this->getGb28181Client()->stopPlayback($deviceId, $channelId);
    }

    public function queryCatalog(string $deviceId): bool
    {
        return $this->getGb28181Client()->queryCatalog($deviceId);
    }

    public function queryDeviceInfo(string $deviceId): bool
    {
        return $this->getGb28181Client()->queryDeviceInfo($deviceId);
    }

    public function queryDeviceStatus(string $deviceId): bool
    {
        return $this->getGb28181Client()->queryDeviceStatus($deviceId);
    }

    public function queryRecord(
        string $deviceId,
        string $channelId,
        string $startTime,
        string $endTime,
        string $type = 'all'
    ): bool {
        return $this->getGb28181Client()->queryRecord($deviceId, $channelId, $startTime, $endTime, $type);
    }

    /**
     * PTZ 云台控制
     * 
     * 支持的命令:
     * - up/down/left/right: 方向控制
     * - zoom_in/zoom_out: 焦距控制
     * - stop: 停止云台运动
     * 
     * 前端实现建议:
     * - 鼠标按下(mousedown): 调用 ptzControl()
     * - 鼠标松开(mouseup): 调用 ptzStop()
     * - 触摸屏: touchstart/touchend
     * 
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $command 控制命令
     * @param int $speed 速度 (1-255)
     * @return bool
     */
    public function ptzControl(
        string $deviceId,
        string $channelId,
        string $command,
        int $speed = 5
    ): bool {
        return $this->getGb28181Client()->ptzControl($deviceId, $channelId, $command, $speed);
    }

    /**
     * PTZ 停止
     * 
     * 用于停止云台运动，前端应在鼠标松开时调用
     * 
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @return bool
     */
    public function ptzStop(string $deviceId, string $channelId): bool
    {
        return $this->getGb28181Client()->ptzControl($deviceId, $channelId, 'stop', 0);
    }

    /**
     * 设置预置位
     * 
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $presetId 预置位编号 (1-255)
     * @return bool
     */
    public function presetSet(string $deviceId, string $channelId, int $presetId): bool
    {
        return $this->getGb28181Client()->presetControl($deviceId, $channelId, 'set', $presetId);
    }

    /**
     * 调用预置位
     * 
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $presetId 预置位编号 (1-255)
     * @return bool
     */
    public function presetCall(string $deviceId, string $channelId, int $presetId): bool
    {
        return $this->getGb28181Client()->presetControl($deviceId, $channelId, 'call', $presetId);
    }

    /**
     * 删除预置位
     * 
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $presetId 预置位编号 (1-255)
     * @return bool
     */
    public function presetDelete(string $deviceId, string $channelId, int $presetId): bool
    {
        return $this->getGb28181Client()->presetControl($deviceId, $channelId, 'delete', $presetId);
    }

    /**
     * GB28181-2022: 设备升级
     * 
     * @param string $deviceId 设备ID
     * @param string $manufacturer 制造商
     * @param string $firmware 固件版本
     * @return array 包含 success, session_id, sn 等信息
     */
    public function deviceUpgrade(string $deviceId, string $manufacturer, string $firmware): array
    {
        return $this->getGb28181Client()->deviceUpgrade($deviceId, $manufacturer, $firmware);
    }

    /**
     * GB28181-2022: 图像抓拍
     * 
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $imageFormat 图片格式 (JPEG/PNG/BMP)
     * @return array 包含 success, session_id, sn 等信息
     */
    public function snapshot(string $deviceId, string $channelId, string $imageFormat = 'JPEG'): array
    {
        return $this->getGb28181Client()->snapshot($deviceId, $channelId, $imageFormat);
    }

    /**
     * 订阅移动设备位置 (SUBSCRIBE/NOTIFY 机制)
     * 
     * GB28181 位置订阅使用 SIP SUBSCRIBE/NOTIFY 机制：
     * 1. 平台发送 SUBSCRIBE (Event: presence, Expires: 订阅时长)
     * 2. 设备响应 200 OK
     * 3. 设备周期性发送 NOTIFY (Event: presence, 包含位置信息)
     * 4. 平台响应 200 OK
     * 
     * 订阅流程：
     * - 首次订阅：subscribeMobilePosition($deviceId, 3600, 60)
     * - 刷新订阅：在过期前再次调用 subscribeMobilePosition
     * - 取消订阅：unsubscribeMobilePosition($deviceId)
     * 
     * @param string $deviceId 设备ID
     * @param int $expires 订阅有效期(秒)，建议 60-3600
     * @param int|null $interval 位置上报间隔(秒)，null表示设备自行决定
     * @return bool
     */
    public function subscribeMobilePosition(string $deviceId, int $expires = 3600, ?int $interval = null): bool
    {
        return $this->getGb28181Client()->subscribeMobilePosition($deviceId, $expires, $interval);
    }

    /**
     * 取消移动设备位置订阅
     * 
     * 发送 SUBSCRIBE (Expires: 0) 取消订阅
     * 设备会发送最后一次 NOTIFY (Subscription-State: terminated) 并停止上报
     * 
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function unsubscribeMobilePosition(string $deviceId): bool
    {
        return $this->getGb28181Client()->unsubscribeMobilePosition($deviceId);
    }

    /**
     * 创建直播会话
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $tcpMode TCP模式
     * @return array|null 会话信息，包含stream_id和zlm_port等
     */
    public function createLiveSession(string $deviceId, string $channelId, int $tcpMode = 1): ?array
    {
        // 生成流ID
        [$ssrc, $streamId] = $this->getSSRCInfo($deviceId, $channelId);
        // 打开RTP服务器
        $portResult = $this->openRtpServer($streamId, $tcpMode);
        if ($portResult['code'] !== 0) {
            return null;
        }

        // 创建会话记录
        $sessionData = [
            'session_id' => uniqid($deviceId . '_' . $channelId . '_'),
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'ssrc' => $ssrc,
            'stream_id' => $streamId,
            'type' => 'live',
            'zlm_port' => $portResult['port'],
            'tcp_mode' => $tcpMode,
            'status' => 'inviting',
            'started_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $session = $this->getDeviceService()->createSession($sessionData);
        
        return [
            'session' => $session,
            'stream_id' => $streamId,
            'ssrc' => $ssrc,
            'zlm_port' => $portResult['port']
        ];
    }

    /**
     * 创建回放会话
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @param int $tcpMode TCP模式
     * @return array|null 会话信息
     */
    public function createPlaybackSession(
        string $deviceId, 
        string $channelId, 
        string $startTime, 
        string $endTime, 
        int $tcpMode = 1
    ): ?array {
        // 生成流ID
        $streamId = $this->generateStreamId($deviceId, $channelId, 'playback_' . time());
        
        // 打开RTP服务器
        $portResult = $this->openRtpServer($streamId, $tcpMode);
        if ($portResult['code'] !== 0) {
            return null;
        }
        
        // 生成SSRC
        $playbackSsrc = $this->getDeviceService()->generateUniqueSsrc();
        
        // 创建会话记录
        $sessionData = [
            'session_id' => uniqid($deviceId . '_' . $channelId . '_'),
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'ssrc' => $playbackSsrc,
            'stream_id' => $streamId,
            'type' => 'playback',
            'zlm_port' => $portResult['port'],
            'tcp_mode' => $tcpMode,
            'status' => 'inviting',
            'start_time' => $startTime,
            'end_time' => $endTime,
            'started_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $session = $this->getDeviceService()->createSession($sessionData);
        
        return [
            'session' => $session,
            'stream_id' => $streamId,
            'ssrc' => $playbackSsrc,
            'zlm_port' => $portResult['port']
        ];
    }

    /**
     * 打开RTP服务器，分配端口时排除冷却中的端口
     *
     * @param string $streamId 流ID
     * @param string $serverId 媒体服务器ID
     * @param int $tcpMode TCP模式
     * @return array ZLM返回的结果
     */
    public function openRtpServer(string $streamId, string $serverId, int $tcpMode = 1): array
    {
        $this->checkZlmState($serverId);
        
        $zlmClient = $this->getZlmClientByServerId($serverId);
        
        // 获取冷却中的端口
        $coolingPorts = $this->getDeviceService()->getCoolingPorts();
        $excludePorts = array_column($coolingPorts, 'zlm_port');
        
        // 尝试打开RTP服务器，最多尝试10次
        $maxAttempts = 10;
        $attempts = 0;
        
        while ($attempts < $maxAttempts) {
            // 调用ZLM打开RTP服务器
            $result = $zlmClient->openRtpServer($streamId, 0, $tcpMode);
            
            // 如果成功且端口不在排除列表中，则返回结果
            if ($result && $result['code'] === 0 && !in_array($result['port'], $excludePorts)) {
                return $result;
            }
            
            // 如果端口在排除列表中，关闭它并尝试下一个
            if ($result && $result['code'] === 0 && in_array($result['port'], $excludePorts)) {
                $zlmClient->closeRtpServer($streamId);
            }
            
            $attempts++;
            
            // 短暂等待后重试
            usleep(100000); // 等待100毫秒
        }
        
        // 如果尝试了最大次数仍未找到合适端口，则返回失败
        return [
            'code' => -1,
            'msg' => '无法分配可用端口，所有端口都在冷却中'
        ];
    }

    /**
     * 关闭RTP服务器
     *
     * @param string $streamId 流ID
     * @return bool 是否成功关闭
     */
    public function closeRtpServer(string $streamId): bool
    {
        // 关闭RTP服务器时同时释放端口
        $this->releaseRtpPort($streamId);
        return $this->getZlmClientByStreamId($streamId)->closeRtpServer($streamId);
    }

    /**
     * 释放RTP端口（标记为冷却状态）
     * 
     * @param string $streamId 流ID
     * @return bool 是否成功释放
     */
    public function releaseRtpPort(string $streamId): bool
    {
        // 获取会话信息
        $session = $this->getDeviceService()->getSessionByStreamId($streamId);
        if (!$session) {
            return false;
        }
        
        // 更新会话状态为已停止，并更新时间戳
        $this->getDeviceService()->updateChannelByMainId($session['stream_id'], [
            'stream_status' => 'idle'
        ]);
        // 这样端口就会进入20秒的冷却期
        return $this->getDeviceService()->updateSession($session['id'], [
            'status' => 'stopped',
            'stopped_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function closeStream(string $schema, string $streamId): bool
    {
        return $this->getZlmClientByStreamId($streamId)->closeStream($schema, $streamId);
    }

    public function updateRtpServerSsrc(string $streamId, string $ssrc): array
    {
        return $this->getZlmClientByStreamId($streamId)->updateRtpServerSsrc($streamId, $ssrc);
    }

    public function getPlayUrls(string $schema, string $streamId, ?string $accessUrl = null): array
    {
        return $this->getZlmClientByStreamId($streamId)->getPlayUrls($streamId, $schema, $accessUrl);
    }

    /**
     * 查询通道音视频信息（从 ZLM 获取实时流参数）
     * 
     * 通过 ZLM 的 getMediaInfo 接口获取流的实际音视频参数：
     * - 视频：编码格式、分辨率、帧率、码率
     * - 音频：编码格式、采样率、声道数
     * 
     * 使用场景：
     * 1. 流建立后查询实际参数
     * 2. 更新数据库 stream 表信息
     * 3. 前端显示流参数
     * 
     * @param string $streamId 流ID
     * @param string $schema 流协议 (rtsp/rtmp/http-flv/ws-flv等)
     * @return array|null 返回音视频信息，流不存在返回 null
     * 
     * 返回格式：
     * [
     *     'online' => true,
     *     'video' => [
     *         'codec' => 'H264',           // 编码格式：H264/H265/PS
     *         'width' => 1920,             // 宽度
     *         'height' => 1080,            // 高度
     *         'fps' => 25,                 // 帧率
     *         'bit_rate' => 2048000        // 码率 (bps)
     *     ],
     *     'audio' => [
     *         'codec' => 'PCMA',           // 编码格式：PCMA/PCMU/AAC/G711A
     *         'sample_rate' => 8000,       // 采样率 (Hz)
     *         'channels' => 1,             // 声道数
     *         'sample_bit' => 16           // 采样位数
     *     ]
     * ]
     */
    public function queryChannelMediaInfo(string $streamId, string $schema = 'rtmp'): ?array
    {
        // 通过 streamId 获取 ZLM 客户端
        $zlmClient = $this->getZlmClientByStreamId($streamId);
        
        // 调用 ZLM getMediaInfo 接口
        $mediaInfo = $zlmClient->getMediaInfo($schema, $streamId);
        
        if (!$mediaInfo || $mediaInfo['code'] !== 0 || !isset($mediaInfo['online']) || !$mediaInfo['online']) {
            return null;
        }
        
        $result = [
            'online' => true,
            'stream_id' => $streamId,
            'schema' => $schema,
        ];
        
        // 解析 tracks 信息
        if (isset($mediaInfo['tracks']) && is_array($mediaInfo['tracks'])) {
            foreach ($mediaInfo['tracks'] as $track) {
                $codecType = $track['codec_type'] ?? null;
                
                // 视频轨道 (codec_type = 0)
                if ($codecType === 0) {
                    $result['video'] = [
                        'codec' => $this->getCodecName($track['codec_id'] ?? 0, 'video'),
                        'codec_id' => $track['codec_id'] ?? 0,
                        'width' => $track['width'] ?? 0,
                        'height' => $track['height'] ?? 0,
                        'fps' => $track['fps'] ?? 0,
                        'bit_rate' => $track['bit_rate'] ?? 0,
                    ];
                }
                
                // 音频轨道 (codec_type = 1)
                if ($codecType === 1) {
                    $result['audio'] = [
                        'codec' => $this->getCodecName($track['codec_id'] ?? 0, 'audio'),
                        'codec_id' => $track['codec_id'] ?? 0,
                        'sample_rate' => $track['sample_rate'] ?? 0,
                        'channels' => $track['channels'] ?? 0,
                        'sample_bit' => $track['sample_bit'] ?? 16,
                    ];
                }
            }
        }
        
        return $result;
    }

    /**
     * 获取编码格式名称
     * 
     * @param int $codecId ZLM 的 codec_id
     * @param string $type 'video' 或 'audio'
     * @return string 编码格式名称
     */
    private function getCodecName(int $codecId, string $type): string
    {
        if ($type === 'video') {
            // 视频编码 ID 映射
            return match($codecId) {
                0 => 'H264',
                1 => 'H265',
                2 => 'AAC',      // 注意：这里可能有误，应该不会出现在视频轨道
                7 => 'H264',     // AVC
                8 => 'JPEG',
                12 => 'H265',    // HEVC
                173 => 'PS',     // MPEG-PS
                default => "Unknown($codecId)"
            };
        } else {
            // 音频编码 ID 映射
            return match($codecId) {
                2 => 'AAC',
                7 => 'G711A',    // PCMA
                8 => 'G711U',    // PCMU
                97 => 'OPUS',
                98 => 'G722',
                99 => 'G729',
                default => "Unknown($codecId)"
            };
        }
    }

    public function generateStreamId(string $deviceId, string $channelId, ?string $suffix = null): string
    {
        $streamId = "{$deviceId}_{$channelId}";
        if ($suffix) {
            $streamId .= "_{$suffix}";
        }
        return $streamId;
    }

    /**
     * 获取非GB28181设备的streamId计算
     *
     * @param string $videoSrcUrl
     * @return string
     */
    public function getDisGB28181VideoChannelMainId(string $videoSrcUrl): string
    {
        $crc32 = CRC32Helper::getCRC32(strtoupper(trim($videoSrcUrl)));
        return sprintf("%08X", $crc32);
    }

    /**
     * 获取GB28181设备实时流的SSRC信息
     *
     * @param string $deviceId
     * @param string $channelId
     * @return array 返回数组，第一个是用于SIP推流的SSRC，第二个是用于ZLM的streamId
     */
    public function getSSRCInfo(string $deviceId, string $channelId): array
    {
        $tag = "0" . substr($channelId, 3, 5) . $deviceId . $channelId;
        $crc32 = CRC32Helper::getCRC32($tag);
        $crc32Str = str_pad((string)$crc32, 10, '0', STR_PAD_LEFT);
        $tmpChars = str_split($crc32Str);
        $tmpChars[0] = '0'; // 实时流ssrc第一位是0
        $ssrcId = implode('', $tmpChars);
        $streamId = sprintf("%08X", (int)$ssrcId);
        
        return [$ssrcId, $streamId];
    }

    /**
     * 更新设备信息
     *
     * @param array $device
     * @return bool
     */
    public function updateDevice(array $device)
    {
        return $this->getGb28181Client()->deviceUpdate($device['device_id'], $device);
    }

    /**
     * 检查 ZLM 状态
     *
     * @param string $serverId 媒体服务器ID
     * @throws ZlmException
     */
    protected function checkZlmState(string $serverId): void
    {
        if ($this->getZlmClientByServerId($serverId)->getVersion() === null) {
            throw new ZlmException('ZLM未启动');
        }
    }
    
    /**
     * 获取设备服务
     * 
     * @return \CoreW\Business\Devices\Service\DeviceService
     */
    protected function getDeviceService()
    {
        return $this->bfw->service('Devices:DeviceService');
    }

    /**
     * 获取媒体服务
     *
     * @return MediaServerService
     */
    protected function getMediaService(): MediaServerService
    {
        return $this->bfw->service('MediaServer:MediaServerService');
    }

    /**
     * @return Gb28181Client
     */
    private function getGb28181Client(): Gb28181Client
    {
        return $this->bfw['gb28181_gateway_sdk'];
    }

    /**
     * @return ZLMClient
     */
    private function getZlmClient(array $config): ZLMClient
    {
        return $this->bfw['zlm_sdk']($config);
    }

    protected function getZlmClientByServerId(string $serverId): ZLMClient
    {
        $mediaServer = $this->getMediaService()->getMediaServerById($serverId);
        if (!$mediaServer || $mediaServer['type'] !== MediaServerType::ZLM->value) {
            throw new ZlmException('未找到对应的ZLM');
        }

        return $this->getZlmClient($mediaServer);
    }

    /**
     * 通过 StreamId 获取 ZLMClient
     * 从会话中查找 media_server_id
     *
     * @param string $streamId
     * @return ZLMClient
     * @throws ZlmException
     */
    protected function getZlmClientByStreamId(string $streamId): ZLMClient
    {
        $session = $this->getDeviceService()->getSessionByStreamId($streamId);
        if (!$session || empty($session['media_server_id'])) {
            throw new ZlmException('未找到对应的流媒体服务器');
        }
        return $this->getZlmClientByServerId($session['media_server_id']);
    }
}