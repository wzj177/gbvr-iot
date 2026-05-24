<?php

namespace CoreW\Business\GB;

use CoreW\Bfw;
use CoreW\Business\Devices\Enums\ChannelStreamStatus;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Enums\MediaServerType;
use CoreW\Business\Devices\Enums\StreamSessionStatus;
use CoreW\Business\Devices\Enums\StreamSessionType;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Dtos\VoiceTalkStreamArrivalDto;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Exception\ZlmException;
use CoreW\Sdk\PSipGateway\Gb28181Client;
use CoreW\Sdk\ZLMediaKit\Dos\SendRtpDo;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use CoreW\Utils\CRC32Helper;
use Ramsey\Uuid\Uuid;
use support\Log;
use support\utils\ArrayToolkit;
use CoreW\Business\BizEnum;

class Gb28181Service
{

    public function __construct(protected Bfw $bfw)
    {
    }

    /**
     * 获取 ZLM 客户端
     * @param string $mediaServerId
     * @param string $streamId
     * @param string $app
     * @return array
     */
    public function getMediaList(string $mediaServerId, string $streamId, string $app = 'rtp') : array
    {
        try {
            $zlmClient = $this->getZlmClientByServerId($mediaServerId);

            return $zlmClient->getMediaList($app, $streamId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 获取 ZLM 播放器列表
     * @param string $mediaServerId
     * @param string $streamId
     * @param string $schema
     * @param string $app
     * @return array
     */
    public function getMediaPlayerList(string $mediaServerId, string $streamId, string $schema, string $app = 'rtp') : array
    {
        try {
            $zlmClient = $this->getZlmClientByServerId($mediaServerId);

            return $zlmClient->getMediaPlayerList($schema, $streamId, $app);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     *
     * @param array $channel
     * @param int $zlmPort
     * @param int $tcpMode
     * @param string|null $streamId
     * @param string|null $streamIp
     * @param bool $seniorSdp
     * @return bool
     * @throws ZlmException
     */
    public function startLiveVideo(
        array $channel,
        int $zlmPort,
        int $tcpMode = 1,
        ?string $streamId = null,
        ?string $streamIp = null,
        bool $seniorSdp = false
    ) : bool
    {
        $this->checkZlmState($channel['media_server_id']);
        return $this->getGb28181Client()->startLiveVideo($channel['device_id'], $channel['channel_id'], $channel['ssrc'], $zlmPort, $tcpMode, $streamId, $streamIp, $seniorSdp);
    }

    public function stopLiveVideo(string $deviceId, string $channelId, string $streamId) : bool
    {
        return $this->getGb28181Client()->stopLiveVideo($deviceId, $channelId, $streamId);
    }

    public function startPlayback(
        array $channel,
        string $startTime,
        string $endTime,
        string $ssrc,
        int $zlmPort,
        int $tcpMode = 1,
        ?string $streamId = null,
        ?string $streamIp = null
    ) : bool
    {
        $this->checkZlmState($channel['media_server_id']);
        return $this->getGb28181Client()->startPlayback($channel['device_id'], $channel['channel_id'], $startTime, $endTime, $ssrc, $zlmPort, $tcpMode, $streamId, $streamIp);
    }

    public function stopPlayback(string $deviceId, string $channelId, string $streamId) : bool
    {
        return $this->getGb28181Client()->stopPlayback($deviceId, $channelId, $streamId);
    }

    /**
     * 发送回放控制命令
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
     * @param string $streamId 流ID (用于标识活跃会话)
     * @param string $action 操作类型
     * @param int|float $speed 倍速（用于快进/慢放）
     * @param string|null $seekTime 拖动时间（ISO8601格式，用于seek操作）
     * @param float $scale 缩放比例（用于scale操作）
     * @return bool
     */
    public function sendPlaybackControl(
        string $deviceId,
        string $channelId,
        string $streamId,
        string $action,
        int|float $speed = 1,
        ?string $seekTime = null,
        float $scale = 1.0
    ) : bool
    {
        return $this->getGb28181Client()->playbackControl(
            $deviceId,
            $channelId,
            $streamId,
            $action,
            $speed,
            $seekTime,
            $scale
        );
    }

    /**
     * 创建下载会话并打开 RTP 端口
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @param array $mediaServer 媒体服务器配置
     * @param int $tcpMode TCP模式
     * @param int $downloadSpeed 下载倍速
     * @return array|null 返回会话信息或null
     */
    public function createDownloadSessionAndOpenRtp(
        string $deviceId,
        string $channelId,
        string $startTime,
        string $endTime,
        array $mediaServer,
        int $tcpMode = 1,
        int $downloadSpeed = 1
    ) : ?array
    {
        try {
            // 创建下载会话（使用独立的 stream_id 前缀 download_）
            return $this->createPlaybackSessionAndOpenRtp(
                $deviceId,
                $channelId,
                $startTime,
                $endTime,
                $mediaServer,
                $tcpMode,
                true  // isDownload = true，使用 download_ 前缀
            );
        } catch (\Throwable $e) {
            Log::channel('sip')->error('Create download session failed', [
                'device_id'  => $deviceId,
                'channel_id' => $channelId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * 开始录像下载
     *
     * 与普通回放的区别：
     * - session_name = 'Download' (而非 'Playback')
     * - 用于将录像下载为文件
     *
     * @param array $channel 通道信息
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @param string $ssrc SSRC
     * @param int $zlmPort RTP端口
     * @param int $tcpMode TCP模式
     * @param string|null $streamId 流ID
     * @param string|null $streamIp 收流IP
     * @param int $downloadSpeed 下载倍速
     * @return bool
     */
    public function startDownload(
        array $channel,
        string $startTime,
        string $endTime,
        string $ssrc,
        int $zlmPort,
        int $tcpMode = 1,
        ?string $streamId = null,
        ?string $streamIp = null,
        int $downloadSpeed = 1
    ) : bool
    {
        $this->checkZlmState($channel['media_server_id']);
        return $this->getGb28181Client()->startDownload(
            $channel['device_id'],
            $channel['channel_id'],
            $startTime,
            $endTime,
            $ssrc,
            $zlmPort,
            $tcpMode,
            $streamId,
            $streamIp,
            $downloadSpeed
        );
    }


    /**
     * 启动语音广播（发送 Broadcast MESSAGE 通知）
     *
     * 广播模式流程（与 talk 模式不同）：
     * 1. 服务端发送 Broadcast MESSAGE 通知给设备
     * 2. 设备处理后主动发送 INVITE 给服务端
     * 3. 服务端回复 200 OK（携带 SDP）
     * 4. 设备发送 ACK
     * 5. ZLM 开始向设备推送音频流
     *
     * @param array $session 会话信息（包含 SSRC、端口等，由 handleStreamArrival 中 startSendRtpPassive 后传入）
     * @return bool
     * @throws ZlmException
     */
    public function startAudioBroadcast(array $session) : bool
    {
        $this->checkZlmState($session['media_server_id']);
        $result = $this->getGb28181Client()->startAudioBroadcast($session);

        return $result['success'] ?? false;
    }

    /**
     * 启动语音对讲（发送 INVITE）
     * @param array $session
     * @return bool
     * @throws ZlmException
     */
    public function startVoiceTalk(array $session) : bool
    {
        $this->checkZlmState($session['media_server_id']);
        // 调用 GB28181Client 的 startVoiceTalk 方法
        $result = $this->getGb28181Client()->startVoiceTalk($session);

        return $result['success'] ?? false;
    }

    /**
     * 停止语音对讲（发送 BYE）
     * @param array $session
     * @return bool
     */
    public function stopVoiceTalk(array $session) : bool
    {
        // 获取 dialog_id（从 metadata 或 dialog_id 字段）
        $dialogId = $session['dialog_id'] ?? null;

        if (!$dialogId) {
            Log::channel('gb_stream')->error("[Gb28181Service] 缺少 dialog_id，无法停止对讲", $session);
            return false;
        }

        $this->getZlmClientByServerId($session['media_server_id'])->stopSendRtp(BizEnum::ZLM_DEFAULT_VHOST, $session['app'] ?? $session['mode'], $session['stream'], $session['ssrc']);

        // 调用 GB28181Client 的 stopVoiceTalk 方法
        $result = $this->getGb28181Client()->stopVoiceTalk(
            $session['device_id'],
            $session['channel_id'],
            $dialogId  // 传递 dialog_id
        );

        return $result['success'] ?? false;
    }

    public function queryCatalog(string $deviceId) : bool
    {
        return $this->getGb28181Client()->queryCatalog($deviceId);
    }

    public function queryDeviceInfo(string $deviceId) : bool
    {
        return $this->getGb28181Client()->queryDeviceInfo($deviceId);
    }


    public function queryDeviceStatus(string $deviceId) : bool
    {
        return $this->getGb28181Client()->queryDeviceStatus($deviceId);
    }

    public function queryRecord(
        string $deviceId,
        string $channelId,
        string $startTime,
        string $endTime,
        string $type = 'all'
    ) : bool
    {
        return $this->getGb28181Client()->queryRecord($deviceId, $channelId, $startTime, $endTime, $type);
    }

    /**
     * PTZ 云台控制
     *
     * 支持的命令:
     * - up/down/left/right: 方向控制
     * - zoom_in/zoom_out: 变倍控制 (放大/缩小)
     * - focus_near/focus_far: 对焦控制 (近焦/远焦)
     * - iris_open/iris_close: 光圈控制 (开大/缩小)
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
    ) : bool
    {
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
    public function ptzStop(string $deviceId, string $channelId) : bool
    {
        return $this->getGb28181Client()->ptzControl($deviceId, $channelId, 'stop', 0);
    }

    /**
     * 对焦近 (聚焦+)
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $speed 速度 (1-255)
     * @return bool
     */
    public function focusNear(string $deviceId, string $channelId, int $speed = 5) : bool
    {
        return $this->getGb28181Client()->ptzControl($deviceId, $channelId, 'focus_near', $speed);
    }

    /**
     * 对焦远 (聚焦-)
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $speed 速度 (1-255)
     * @return bool
     */
    public function focusFar(string $deviceId, string $channelId, int $speed = 5) : bool
    {
        return $this->getGb28181Client()->ptzControl($deviceId, $channelId, 'focus_far', $speed);
    }

    /**
     * 光圈开大
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $speed 速度 (1-255)
     * @return bool
     */
    public function irisOpen(string $deviceId, string $channelId, int $speed = 5) : bool
    {
        return $this->getGb28181Client()->ptzControl($deviceId, $channelId, 'iris_open', $speed);
    }

    /**
     * 光圈缩小
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $speed 速度 (1-255)
     * @return bool
     */
    public function irisClose(string $deviceId, string $channelId, int $speed = 5) : bool
    {
        return $this->getGb28181Client()->ptzControl($deviceId, $channelId, 'iris_close', $speed);
    }

    /**
     * 设置预置位
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $presetId 预置位编号 (1-255)
     * @return bool
     */
    public function presetSet(string $deviceId, string $channelId, int $presetId) : bool
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
    public function presetCall(string $deviceId, string $channelId, int $presetId) : bool
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
    public function presetDelete(string $deviceId, string $channelId, int $presetId) : bool
    {
        return $this->getGb28181Client()->presetControl($deviceId, $channelId, 'delete', $presetId);
    }

    /**
     * 添加巡航点到巡航组
     *
     * 巡航点基于预置位，需要先设置预置位
     *
     * 使用示例：
     * ```php
     * // 1. 先设置3个预置位
     * $this->presetSet($deviceId, $channelId, 1);  // 入口
     * $this->presetSet($deviceId, $channelId, 2);  // 停车区A
     * $this->presetSet($deviceId, $channelId, 3);  // 停车区B
     *
     * // 2. 将预置位加入巡航组0
     * $this->cruiseAddPoint($deviceId, $channelId, 0, 1);
     * $this->cruiseAddPoint($deviceId, $channelId, 0, 2);
     * $this->cruiseAddPoint($deviceId, $channelId, 0, 3);
     *
     * // 3. 设置巡航参数
     * $this->cruiseSetSpeed($deviceId, $channelId, 0, 80);    // 速度
     * $this->cruiseSetDuration($deviceId, $channelId, 0, 10); // 每点停10秒
     *
     * // 4. 开始巡航
     * $this->cruiseStart($deviceId, $channelId, 0);
     * ```
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $groupId 巡航组号 (0-255)
     * @param int $presetId 预置位编号 (1-255)
     * @return bool
     */
    public function cruiseAddPoint(string $deviceId, string $channelId, int $groupId, int $presetId) : bool
    {
        return $this->getGb28181Client()->cruiseControl($deviceId, $channelId, 'add_point', $groupId, $presetId);
    }

    /**
     * 从巡航组删除巡航点
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $groupId 巡航组号 (0-255)
     * @param int $presetId 预置位编号 (1-255)
     * @return bool
     */
    public function cruiseDeletePoint(string $deviceId, string $channelId, int $groupId, int $presetId) : bool
    {
        return $this->getGb28181Client()->cruiseControl($deviceId, $channelId, 'delete_point', $groupId, $presetId);
    }

    /**
     * 设置巡航速度
     *
     * 注意：实际速度受设备厂商限制，不会过快（出于保护机制）
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $groupId 巡航组号 (0-255)
     * @param int $speed 巡航速度 (1-4095)
     * @return bool
     */
    public function cruiseSetSpeed(string $deviceId, string $channelId, int $groupId, int $speed) : bool
    {
        return $this->getGb28181Client()->cruiseControl($deviceId, $channelId, 'set_speed', $groupId, $speed);
    }

    /**
     * 设置巡航停留时间
     *
     * 注意：实际停留时间受设备厂商限制，不会过短（出于保护机制）
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $groupId 巡航组号 (0-255)
     * @param int $duration 停留时间（秒，1-4095）
     * @return bool
     */
    public function cruiseSetDuration(string $deviceId, string $channelId, int $groupId, int $duration) : bool
    {
        return $this->getGb28181Client()->cruiseControl($deviceId, $channelId, 'set_duration', $groupId, $duration);
    }

    /**
     * 开始巡航
     *
     * 摄像头将按照巡航组内设置的预置位顺序循环移动
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param int $groupId 巡航组号 (0-255)
     * @return bool
     */
    public function cruiseStart(string $deviceId, string $channelId, int $groupId) : bool
    {
        return $this->getGb28181Client()->cruiseControl($deviceId, $channelId, 'start', $groupId, 0);
    }

    /**
     * 停止巡航
     *
     * 通过发送 PTZ 停止命令来停止巡航
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @return bool
     */
    public function cruiseStop(string $deviceId, string $channelId) : bool
    {
        // 停止巡航使用普通的 PTZ 停止命令
        return $this->ptzStop($deviceId, $channelId);
    }

    /**
     * GB28181-2022: 设备升级
     *
     * @param string $deviceId 设备ID
     * @param string $manufacturer 制造商
     * @param string $firmware 固件版本
     * @return array 包含 success, session_id, sn 等信息
     */
    public function deviceUpgrade(string $deviceId, string $manufacturer, string $firmware) : array
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
    public function snapshot(string $deviceId, string $channelId, string $imageFormat = 'JPEG') : array
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
    public function subscribeMobilePosition(string $deviceId, array $params = []) : array
    {
        return $this->getGb28181Client()->subscribeMobilePosition($deviceId, $params);
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
    public function unsubscribeMobilePosition(string $deviceId) : bool
    {
        return $this->getGb28181Client()->unsubscribeMobilePosition($deviceId);
    }

    /**
     * 订阅目录变更
     *
     * @param string $deviceId 设备ID
     * @param array $params 参数，包含 expires 等字段
     * @return array 返回结果，包含 success, dialog_id, error 等字段
     */
    public function subscribeCatalog(string $deviceId, array $params = []) : array
    {
        return $this->getGb28181Client()->subscribeCatalog($deviceId, $params);
    }

    /**
     * 取消目录订阅
     *
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function unsubscribeCatalog(string $deviceId) : bool
    {
        return $this->getGb28181Client()->unsubscribeCatalog($deviceId);
    }

    /**
     * 订阅报警事件
     *
     * @param string $deviceId 设备ID
     * @param array $params 参数，包含 expires, start_priority, end_priority, alarm_method 等字段
     * @return array 返回结果，包含 success, dialog_id, error 等字段
     */
    public function subscribeAlarm(string $deviceId, array $params = []) : array
    {
        return $this->getGb28181Client()->subscribeAlarm($deviceId, $params);
    }

    /**
     * 取消报警订阅
     *
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function unsubscribeAlarm(string $deviceId) : bool
    {
        return $this->getGb28181Client()->unsubscribeAlarm($deviceId);
    }

    /**
     * 刷新订阅(续订)
     *
     * @param int $dialogId Dialog ID
     * @param string $event 订阅事件类型 (Catalog/Alarm/MobilePosition)
     * @param int $expires 新的订阅有效期(秒)
     * @return array 返回结果，包含 success, error 等字段
     */
    public function refreshSubscribe(int $dialogId, string $event, int $expires) : array
    {
        return $this->getGb28181Client()->refreshSubscribe($dialogId, $event, $expires);
    }

    /**
     * 创建直播会话
     *
     * @param array $channel 通道
     * @param array $mediaServer 媒体服务器
     * @param int $tcpMode TCP模式
     * @return array|null 会话信息，包含stream_id和rtp_port等
     */
    public function createLiveSessionAndOpenRtp(array $channel, array $mediaServer, int $tcpMode = 1) : ?array
    {
        if ($mediaServer['type'] === MediaServerType::ZLM->value) {
            //  最小修改：检查流是否已存在且活跃
            $info = $this->getRtpInfo($channel['stream_id'], $mediaServer['server_id']);
            if ($info['exist'] ?? false) {
                // 检查是否有活跃 session
                $activeSession = $this->getDeviceService()->getActiveSessionByStreamIdAndType($channel['stream_id'], StreamSessionType::LIVE->value);

                if ($activeSession) {
                    //  复用现有流，增加 viewer 计数
                    $this->incrementViewerCount($channel['stream_id']);
                    Log::channel('gb_stream')->info("[Gb28181Service] 复用现有流: {$channel['stream_id']}");

                    return [
                        'session'   => $activeSession,
                        'stream_id' => $channel['stream_id'],
                        'ssrc'      => $channel['ssrc'],
                        'rtp_port'  => $activeSession['rtp_port'],
                        'reused'    => true,  // 标记为复用
                    ];
                }

                // 流存在但 session 不活跃（僵尸流），清理
                Log::channel('gb_stream')->info("[Gb28181Service] 清理僵尸流: {$channel['stream_id']}");
                $this->closeRtpServer($channel['stream_id'], $mediaServer['server_id']);
            }

            // 打开RTP服务器
            $portResult = $this->openRtpServer($channel['stream_id'], $mediaServer['server_id'], $tcpMode);
            if ($portResult['code'] !== 0) {
                return null;
            }

            // 创建会话记录
            $sessionData = [
                'session_id'      => Uuid::uuid4(),//uniqid($channel['device_id'] . '_' . $channel['channel_id'] . '_' . $channel['media_server_id'] . '_'),
                'device_id'       => $channel['device_id'],
                'channel_id'      => $channel['channel_id'],
                'ssrc'            => $channel['ssrc'],
                'stream_id'       => $channel['stream_id'],
                'media_server_id' => $channel['media_server_id'],
                'type'            => StreamSessionType::LIVE->value,
                'rtp_port'        => $portResult['port'],
                'tcp_mode'        => $tcpMode,
                'status'          => StreamSessionStatus::Inviting->value,
                'started_at'      => date('Y-m-d H:i:s'),
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ];

            $session = $this->getDeviceService()->createSession($sessionData);

            //  初始化 viewer 计数为 1
            $this->incrementViewerCount($channel['stream_id']);

            return [
                'session'   => $session,
                'stream_id' => $channel['stream_id'],
                'ssrc'      => $channel['ssrc'],
                'rtp_port'  => $portResult['port'],
                'reused'    => false,
            ];
        }


        return null;
    }

    /**
     * 创建回放会话
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @param array $mediaServer 媒体服务器
     * @param int $tcpMode TCP模式
     * @return array|null 会话信息
     */
    public function createPlaybackSessionAndOpenRtp(
        string $deviceId,
        string $channelId,
        string $startTime,
        string $endTime,
        array $mediaServer,
        int $tcpMode = 1,
        bool $isDownload = false
    ) : ?array
    {
        if ($mediaServer['type'] === MediaServerType::ZLM->value) {
            // 生成流ID：回放用 pb_，下载用 download_
            // 使用时间戳计算 CRC32，确保格式统一、长度固定
            $startTimestamp = strtotime($startTime);
            $endTimestamp = strtotime($endTime);
            $crcStr = crc32($startTimestamp . '_' . $endTimestamp);
            $suffix = $isDownload ? 'download_' . $crcStr : 'pb_' . $crcStr;
            $streamId = $this->generateStreamId($deviceId, $channelId, $suffix);

            // 流复用检查
            $info = $this->getRtpInfo($streamId, $mediaServer['server_id']);
            if ($info['exist'] ?? false) {
                // 根据类型检查对应的活跃 session
                $sessionType = $isDownload ? StreamSessionType::DOWNLOAD->value : StreamSessionType::PLAYBACK->value;
                $activeSession = $this->getDeviceService()->getActiveSessionByStreamIdAndType($streamId, $sessionType);

                if ($activeSession) {
                    // 复用现有流
                    $this->incrementViewerCount($streamId);
                    $logMsg = $isDownload ? "复用现有下载流" : "复用现有回放流";
                    Log::channel('gb_stream')->info("[Gb28181Service] {$logMsg}: {$streamId}");
                    return [
                        'session'   => $activeSession,
                        'stream_id' => $streamId,
                        'ssrc'      => $activeSession['ssrc'],
                        'rtp_port'  => $activeSession['rtp_port'],
                        'reused'    => true,
                    ];
                }

                // 僵尸流清理
                $logMsg = $isDownload ? "清理僵尸下载流" : "清理僵尸回放流";
                Log::channel('gb_stream')->info("[Gb28181Service] {$logMsg}: {$streamId}");
                $this->closeRtpServer($streamId, $mediaServer['server_id']);
            }

            // 打开RTP服务器
            $portResult = $this->openRtpServer($streamId, $mediaServer['server_id'], $tcpMode);
            if ($portResult['code'] !== 0) {
                return null;
            }

            // 生成SSRC
            //            $playbackSsrc = $this->getDeviceService()->generateUniqueSsrc();
            $playbackSsrc = $this->getSSRCFactory()->getPlayBackSsrc($mediaServer['server_id']);

            // 创建会话记录
            $sessionData = [
                'session_id'      => Uuid::uuid4(),
                'device_id'       => $deviceId,
                'channel_id'      => $channelId,
                'ssrc'            => $playbackSsrc,
                'stream_id'       => $streamId,
                'type'            => !$isDownload ? StreamSessionType::PLAYBACK->value : StreamSessionType::DOWNLOAD->value,
                'rtp_port'        => $portResult['port'],
                'media_server_id' => $mediaServer['server_id'],
                'tcp_mode'        => $tcpMode,
                'status'          => StreamSessionStatus::Inviting->value,
                'start_time'      => $startTime,
                'end_time'        => $endTime,
                'started_at'      => date('Y-m-d H:i:s'),
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ];

            $session = $this->getDeviceService()->createSession($sessionData);

            //  初始化回放流 viewer 计数
            $this->incrementViewerCount($streamId);

            return [
                'session'   => $session,
                'stream_id' => $streamId,
                'ssrc'      => $playbackSsrc,
                'rtp_port'  => $portResult['port'],
                'reused'    => false,
            ];
        }

        return null;
    }

    /**
     * 打开RTP服务器，分配端口时排除冷却中的端口
     *
     * @param string $streamId 流ID
     * @param string $serverId 媒体服务器ID
     * @param int $tcpMode TCP模式
     * @return array ZLM返回的结果
     */
    public function openRtpServer(string $streamId, string $serverId, int $tcpMode = 1) : array
    {
        $this->checkZlmState($serverId);

        $zlmClient = $this->getZlmClientByServerId($serverId);

        // 获取冷却中的端口
        $coolingPorts = $this->getDeviceService()->getCoolingPorts();
        $excludePorts = array_column($coolingPorts, 'rtp_port');

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
        Log::channel('gb_stream')->error("[Gb28181Service] 无法分配可用端口，所有端口都在冷却中");
        return [
            'code' => -1,
            'msg'  => '无法分配可用端口，所有端口都在冷却中',
        ];
    }


    public function getRtpInfo(string $streamId, string $serverId)
    {
        $this->checkZlmState($serverId);
        $zlmClient = $this->getZlmClientByServerId($serverId);

        return $zlmClient->getRtpInfo($streamId);
    }

    /**
     * 关闭RTP服务器
     *
     * @param string $streamId 流ID
     * @param string $mediaServerId 媒体服务器ID
     * @return array|null 关闭结果
     */
    public function closeRtpServer(string $streamId, string $mediaServerId) : ?array
    {
        // 关闭RTP服务器时同时释放端口
        $this->releaseStreamSessionRtpPort($streamId);

        return $this->getZlmClientByServerId($mediaServerId)->closeRtpServer($streamId);
    }

    /**
     * 释放RTP端口（标记为冷却状态）
     *
     * @param string $streamId 流ID
     * @return array|null 释放结果
     */
    public function releaseStreamSessionRtpPort(string $streamId, bool $delSession = false) : bool
    {
        // 获取会话信息
        $session = $this->getDeviceService()->getSessionByStreamId($streamId);
        if (!$session) {
            return false;
        }

        // 更新会话状态为已停止，并更新时间戳
        $this->getDeviceService()->updateChannelByMainId($session['stream_id'], [
            'stream_status' => ChannelStreamStatus::IDLE->value,
        ]);
        // 这样端口就会进入20秒的冷却期
        if ($delSession) {
            $this->getDeviceService()->deleteSession($session['id']);
        } else {
            $this->getDeviceService()->updateSession($session['id'], [
                'status'     => StreamSessionStatus::Stopped->value,
                'stopped_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return true;
    }

    /**
     * 关闭流
     * @param string $schema
     * @param string $streamId
     * @return bool
     * @throws ZlmException
     */
    public function closeStream(string $schema, string $streamId) : bool
    {
        return $this->getZlmClientByStreamId($streamId)->closeStream($schema, $streamId);
    }

    public function updateRtpServerSsrc(string $streamId, string $ssrc) : array
    {
        return $this->getZlmClientByStreamId($streamId)->updateRtpServerSsrc($streamId, $ssrc);
    }

    /**
     * 获取播放地址
     *
     * @param string $serverId 媒体服务器ID
     * @param string $streamId 流ID
     * @param string|null $accessUrl 访问地址
     * @param string $app 应用名
     * @return array 播放地址
     */
    public function getPlayUrls(string $serverId, string $streamId, ?string $accessUrl = null, string $app = 'rtp') : array
    {
        return $this->getZlmClientByServerId($serverId)->getPlayUrls($streamId, $app, $accessUrl);
    }

    /**
     * 查询流音视频信息（从 ZLM 获取实时流参数）
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
     * @param string $app 应用名
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
    public function queryStreamSchemaMediaInfo(string $streamId, string $schema = 'fmp4', string $app = 'rtp') : ?array
    {
        // 通过 streamId 获取 ZLM 客户端
        $zlmClient = $this->getZlmClientByStreamId($streamId);

        // 调用 ZLM getMediaInfo 接口
        $mediaList = $zlmClient->getMediaList($app, $streamId);
        if (empty($mediaList)) {
            return null;
        }

        $result = [
            'online'    => true,
            'stream_id' => $streamId,
        ];

        $mediaInfo = ArrayToolkit::find($mediaList, 'schema', $schema);

        // 解析 tracks 信息
        if (isset($mediaInfo['tracks']) && is_array($mediaInfo['tracks'])) {
            foreach ($mediaInfo['tracks'] as $track) {
                $codecType = $track['codec_type'] ?? null;

                // 视频轨道 (codec_type = 0)
                if ($codecType === 0) {
                    $result['video'] = [
                        'codec'    => $this->getCodecName($track['codec_id'] ?? 0, 'video'),
                        'codec_id' => $track['codec_id'] ?? 0,
                        'width'    => $track['width'] ?? 0,
                        'height'   => $track['height'] ?? 0,
                        'fps'      => $track['fps'] ?? 0,
                        'bit_rate' => $track['bit_rate'] ?? 0,
                    ];
                }

                // 音频轨道 (codec_type = 1)
                if ($codecType === 1) {
                    $result['audio'] = [
                        'codec'       => $this->getCodecName($track['codec_id'] ?? 0, 'audio'),
                        'codec_id'    => $track['codec_id'] ?? 0,
                        'sample_rate' => $track['sample_rate'] ?? 0,
                        'channels'    => $track['channels'] ?? 0,
                        'sample_bit'  => $track['sample_bit'] ?? 16,
                    ];
                }
            }
        }

        $result['reader_count'] = $mediaInfo['readerCount'];
        $result['total_reader_count'] = $mediaInfo['totalReaderCount'];

        return $result;
    }

    /**
     * 获取编码格式名称
     *
     * @param int $codecId ZLM 的 codec_id
     * @param string $type 'video' 或 'audio'
     * @return string 编码格式名称
     */
    private function getCodecName(int $codecId, string $type) : string
    {
        if ($type === 'video') {
            // 视频编码 ID 映射
            return match ($codecId) {
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
            return match ($codecId) {
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

    // ========== 简单设备控制命令 ==========

    /**
     * 远程重启
     */
    public function teleBoot(string $deviceId, string $channelId) : bool
    {
        return $this->getGb28181Client()->teleBoot($deviceId, $channelId);
    }

    /**
     * 录像控制
     * @param string $action Record / StopRecord
     */
    public function recordControl(string $deviceId, string $channelId, string $action) : bool
    {
        return $this->getGb28181Client()->recordControl($deviceId, $channelId, $action);
    }

    /**
     * 布防/撤防
     * @param string $action SetGuard / ResetGuard
     */
    public function guardControl(string $deviceId, string $channelId, string $action) : bool
    {
        return $this->getGb28181Client()->guardControl($deviceId, $channelId, $action);
    }

    /**
     * 报警复位
     */
    public function alarmReset(string $deviceId, string $channelId, ?int $alarmMethod = null, ?int $alarmType = null) : bool
    {
        return $this->getGb28181Client()->alarmReset($deviceId, $channelId, $alarmMethod, $alarmType);
    }

    /**
     * 强制关键帧请求
     */
    public function requestIFrame(string $deviceId, string $channelId) : bool
    {
        return $this->getGb28181Client()->iFrameCmd($deviceId, $channelId);
    }

    // ========== 复合设备控制命令 ==========

    /**
     * 看守位控制
     */
    public function homePosition(string $deviceId, string $channelId, bool $enabled, int $resetTime = 0, int $presetIndex = 1) : bool
    {
        return $this->getGb28181Client()->homePosition($deviceId, $channelId, $enabled, $resetTime, $presetIndex);
    }

    /**
     * 拖拽变倍
     */
    public function dragZoom(string $deviceId, string $channelId, string $type, array $params) : bool
    {
        return $this->getGb28181Client()->dragZoom($deviceId, $channelId, $type, $params);
    }

    /**
     * 设备基础配置
     */
    public function deviceConfig(string $deviceId, string $channelId, array $params) : bool
    {
        return $this->getGb28181Client()->deviceConfig($deviceId, $channelId, $params);
    }

    // ========== 前端扩展指令 ==========

    /**
     * 自动扫描控制
     */
    public function scanControl(string $deviceId, string $channelId, string $action, int $groupId = 0, int $speed = 0) : bool
    {
        return $this->getGb28181Client()->scanControl($deviceId, $channelId, $action, $groupId, $speed);
    }

    /**
     * 雨刷控制
     */
    public function wiperControl(string $deviceId, string $channelId, bool $on) : bool
    {
        return $this->getGb28181Client()->wiperControl($deviceId, $channelId, $on);
    }

    /**
     * 辅助开关控制
     */
    public function auxControl(string $deviceId, string $channelId, int $switchId, bool $on) : bool
    {
        return $this->getGb28181Client()->auxControl($deviceId, $channelId, $switchId, $on);
    }

    // ========== 设备查询增强 ==========

    /**
     * 设备预置位查询（从设备获取）
     */
    public function presetQuery(string $deviceId, string $channelId) : bool
    {
        return $this->getGb28181Client()->presetQuery($deviceId, $channelId);
    }

    /**
     * 设备配置查询
     */
    public function configDownload(string $deviceId, string $channelId, string $configType = 'BasicParam') : bool
    {
        return $this->getGb28181Client()->configDownload($deviceId, $channelId, $configType);
    }

    private function generateStreamId(string $deviceId, string $channelId, ?string $suffix = null) : string
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
    public function getDisGB28181VideoChannelMainId(string $videoSrcUrl) : string
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
    public function getSSRCInfo(string $deviceId, string $channelId) : array
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
    protected function checkZlmState(string $serverId) : void
    {
        if ($this->getZlmClientByServerId($serverId)->getVersion() === null) {
            throw new ZlmException('ZLM未启动');
        }
    }


    /**
     * 获取媒体服务
     *
     * @return MediaServerService
     */
    protected function getMediaService() : MediaServerService
    {
        return $this->bfw->service('MediaServer:MediaServerService');
    }

    /**
     * @return Gb28181Client
     */
    private function getGb28181Client() : Gb28181Client
    {

        return $this->bfw['gb28181_gateway_sdk'];
    }

    /**
     * @return ZLMClient
     */
    private function getZlmClient(array $config) : ZLMClient
    {
        return $this->bfw['zlm_sdk']($config);
    }

    public function getZlmClientByServerId(string $serverId) : ZLMClient
    {
        $mediaServer = $this->getMediaService()->getMediaServerByServerId($serverId);
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
    protected function getZlmClientByStreamId(string $streamId) : ZLMClient
    {
        $session = $this->getDeviceService()->getSessionByStreamId($streamId);
        if (!$session || empty($session['media_server_id'])) {
            throw new ZlmException('未找到对应的流媒体服务器');
        }
        return $this->getZlmClientByServerId($session['media_server_id']);
    }

    /**
     *  增加流的 viewer 计数（最小修改：内存实现）
     */
    public function incrementViewerCount(string $streamId) : int|bool
    {
        return $this->getDeviceService()->incrementSessionViewerCount($streamId);
    }

    /**
     *  减少流的 viewer 计数，返回剩余计数
     */
    public function decrementViewerCount(string $streamId) : int|bool
    {
        return $this->getDeviceService()->decrementSessionViewerCount($streamId);
    }


    /**
     * 处理语音对讲流到达事件
     * 使用 DTO 模式封装参数
     *
     * @param VoiceTalkStreamArrivalDto $dto
     * @return array|null 返回 RTP 端口信息，失败返回 null
     */
    public function handleVoiceTalkStreamArrival(VoiceTalkStreamArrivalDto $dto) : ?array
    {
        $zlmClient = $this->getZlmClientByServerId($dto->getMediaServerId());

        // 创建 SendRtpDo - 被动推流模式（语音对讲）
        // RTP 参数从 DTO 获取，由上层 VoiceTalkServiceImpl 根据 mode 设置
        // stream = 用户推流的源流（ZLM 从此流读取音频发送给设备）
        // recv_stream_id = 设备推流的目标流名（ZLM 用此名注册从设备接收的音频）
        $sendRtpDo = SendRtpDo::createPassive($dto->getApp(), $dto->getStream(), $dto->getSsrc())
            ->setSrcPort($dto->getRtpPort())
            ->setPt($dto->getPt())
            ->setUsePs($dto->isUsePs())
            ->setOnlyAudio($dto->isOnlyAudio())
            ->setIsTcp($dto->isTcp())
            ->setRecvStreamId($dto->getReceiveStreamId());

        // 调用 ZLM API
        $result = $zlmClient->startSendRtpPassiveWithDo($sendRtpDo);

        if ($result && $result['code'] == 0) {
            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '语音对讲 RTP 推流启动成功',
                [
                    'app'               => $dto->getApp(),
                    'stream'            => $dto->getStream(),
                    'receive_stream_id' => $dto->getReceiveStreamId(),
                    'local_port'        => $result['local_port'] ?? null,
                ]
            );
        } else {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '语音对讲 RTP 推流启动失败',
                [
                    'error' => $result['msg'] ?? 'unknown',
                    'dto'   => $dto->toArray(),
                ]
            );
        }

        return $result;
    }

    /**
     * 生成接收流ID
     */
    private function generateReceiveStreamId(string $originalStream) : string
    {
        // 原始流: rtp/34020000001320000009/talk
        // 接收流: rtp/34020000001320000009/talk_recv
        return $originalStream . '_recv';
    }


    protected function getLogService() : SystemLogService
    {
        return $this->bfw->service('SystemLog:SystemLogService');
    }

    /**
     * 获取设备服务
     *
     * @return DeviceService
     */
    protected function getDeviceService() : DeviceService
    {
        return $this->bfw->service('Devices:DeviceService');
    }

    protected function getSSRCFactory() : SSRCFactory
    {
        return $this->bfw['SSRCFactory'];
    }
}