<?php

/**
 * GB28181 命令分发器
 *
 * 职责：
 * 1. 接收 Redis 队列的命令请求
 * 2. 根据 action 分发到对应的处理方法
 * 3. 调用 QuerySender 和 SIP 服务器执行实际操作
 *
 * 支持的操作：
 * - start_live_video: 开始实时视频
 * - stop_live_video: 停止实时视频
 * - start_playback: 开始录像回放
 * - query_device_info: 查询设备信息
 * - query_device_status: 查询设备状态
 * - query_record: 查询录像文件
 * - ptz_control: PTZ 云台控制
 * - query_catalog: 查询设备目录(已实现)
 * - cruise_add_point: 添加巡航点
 * - cruise_delete_point: 删除巡航点
 * - cruise_set_speed: 设置巡航速度
 * - cruise_set_duration: 设置巡航停留时间
 * - cruise_start: 开始巡航
 * - cruise_stop: 停止巡航
 */

namespace Gb28181\GateWay\Message;

use Gb28181\GateWay\Device\DeviceManager;
use Gb28181\GateWay\Libs\Logger;

class CommandDispatcher
{
    private \ExoSip $sipServer;
    private QuerySender $querySender;
    private DeviceManager $deviceManager;
    private array $config;
    private array $activeSessions = [];  // 活跃的会话(INVITE)
    private array $pendingSubscribes = [];  // 等待响应的订阅请求 (subscription_id => context)
    private array $pendingBroadcasts = [];  // 等待设备 INVITE 的广播会话 (channelId => session data)
    private Logger $logger;

    /**
     * 构造函数
     */
    public function __construct(\ExoSip $sipServer, QuerySender $querySender, DeviceManager $deviceManager, array $config = [])
    {
        $this->sipServer = $sipServer;
        $this->querySender = $querySender;
        $this->deviceManager = $deviceManager;
        $this->config = array_merge([
            'server_id'    => '',
            'debug'        => false,
            'api_hook_url' => 'http://127.0.0.1:8082/api/v2/gb/hook',  // API Hook URL
        ], $config);
        $this->logger = Logger::getInstance();
    }

    /**
     * 分发命令
     *
     * @param array $command 命令数据
     * @return array 执行结果
     */
    public function dispatch(array $command) : array
    {
        $action = $command['action'] ?? '';
        $deviceId = $command['device_id'] ?? '';
        $channelId = $command['channel_id'] ?? $deviceId;
        $params = $command['params'] ?? [];
        $requestId = $command['request_id'] ?? uniqid();

        $this->log("Dispatch command: {$action} (Device: {$deviceId}, Channel: {$channelId})");

        // refresh_subscribe 不需要设备检查，只需要 dialog_id
        if ($action === 'refresh_subscribe') {
            return $this->handleRefreshSubscribe($requestId, (int)($params['dialog_id'] ?? 0), $params);
        }

        // 检查设备是否在线
        $device = $this->deviceManager->getDevice($deviceId);
        if (!$device) {
            return $this->errorResponse($requestId, "Device not found: {$deviceId}");
        }

        if (!$device['registered']) {
            return $this->errorResponse($requestId, "Device not registered: {$deviceId}");
        }

        // 获取设备地址
        $deviceIp = $device['received_ip'] ?? $device['ip'] ?? '127.0.0.1';
        $devicePort = $device['received_port'] ?? $device['port'] ?? 5060;

        // 根据 action 分发
        try {
            return match ($action) {
                'start_live_video' => $this->handleStartLiveVideo($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'stop_live_video' => $this->handleStopLiveVideo($requestId, $deviceId, $channelId, $params),
                'start_playback' => $this->handleStartPlayback($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'stop_playback' => $this->handleStopPlayback($requestId, $deviceId, $channelId, $params),
                'query_device_info' => $this->handleQueryDeviceInfo($requestId, $deviceId, $deviceIp, $devicePort),
                'query_device_status' => $this->handleQueryDeviceStatus($requestId, $deviceId, $deviceIp, $devicePort),
                'query_record' => $this->handleQueryRecord($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'ptz_control' => $this->handlePtzControl($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'preset_set' => $this->handlePresetSet($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'preset_call' => $this->handlePresetCall($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'preset_delete' => $this->handlePresetDelete($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'cruise_add_point' => $this->handleCruiseAddPoint($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'cruise_delete_point' => $this->handleCruiseDeletePoint($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'cruise_set_speed' => $this->handleCruiseSetSpeed($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'cruise_set_duration' => $this->handleCruiseSetDuration($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'cruise_start' => $this->handleCruiseStart($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'cruise_stop' => $this->handleCruiseStop($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'device_upgrade' => $this->handleDeviceUpgrade($requestId, $deviceId, $deviceIp, $devicePort, $params),
                'snapshot' => $this->handleSnapshot($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'query_catalog' => $this->handleQueryCatalog($requestId, $deviceId, $deviceIp, $devicePort),
                'query_mobile_position' => $this->handleQueryMobilePosition($requestId, $deviceId, $deviceIp, $devicePort, $params),
                'device_update' => $this->handleDeviceUpdate($requestId, $deviceId, $params),
                'subscribe_catalog' => $this->handleSubscribeCatalog($requestId, $deviceId, $params),
                'subscribe_alarm' => $this->handleSubscribeAlarm($requestId, $deviceId, $params),
                'subscribe_mobile_position' => $this->handleSubscribeMobilePosition($requestId, $deviceId, $params),
                'unsubscribe_catalog' => $this->handleUnsubscribeCatalog($requestId, $deviceId),
                'unsubscribe_alarm' => $this->handleUnsubscribeAlarm($requestId, $deviceId),
                'unsubscribe_mobile_position' => $this->handleUnsubscribeMobilePosition($requestId, $deviceId),
                'playback_control' => $this->handlePlaybackControl($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'download_record' => $this->handleDownloadRecord($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'voice_invite' => $this->handleVoiceInvite($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'voice_broadcast' => $this->handleVoiceBroadcast($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'voice_bye' => $this->handleVoiceBye($requestId, $deviceId, $channelId, $params),
                // Phase 1: 简单设备控制命令
                'tele_boot' => $this->handleTeleBoot($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'record_cmd' => $this->handleRecordCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'guard_cmd' => $this->handleGuardCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'alarm_reset' => $this->handleAlarmReset($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'iframe_cmd' => $this->handleIFrameCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                // Phase 2: 复合设备控制命令
                'home_position' => $this->handleHomePosition($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'drag_zoom' => $this->handleDragZoom($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'device_config' => $this->handleDeviceConfig($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                // Phase 3: 前端扩展指令
                'scan_start' => $this->handleFrontEndCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, 0x15, $params),
                'scan_stop' => $this->handleFrontEndCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, 0x16, $params),
                'scan_set_left' => $this->handleFrontEndCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, 0x17, $params),
                'scan_set_right' => $this->handleFrontEndCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, 0x18, $params),
                'scan_set_speed' => $this->handleFrontEndCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, 0x19, $params),
                'wiper_on' => $this->handleFrontEndCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, 0x21, $params),
                'wiper_off' => $this->handleFrontEndCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, 0x22, $params),
                'aux_on' => $this->handleFrontEndCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, 0x40, $params),
                'aux_off' => $this->handleFrontEndCmd($requestId, $deviceId, $channelId, $deviceIp, $devicePort, 0x41, $params),
                // Phase 4: 设备查询增强
                'preset_query' => $this->handlePresetQuery($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'config_download' => $this->handleConfigDownload($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                default => $this->errorResponse($requestId, "Unknown action: {$action}"),
            };
        } catch (\Exception $e) {
            return $this->errorResponse($requestId, "Exception: {$e->getMessage()}");
        }
    }

    /**
     * 开始实时视频
     * 流程: INVITE -> 200 OK -> ACK
     *
     * 注意：SSRC和ZLM端口由gbvr-iot分配，通过params传入
     */
    private function handleStartLiveVideo(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("Start live video: {$channelId}");

        // 调试:打印接收到的params
        if ($this->config['debug']) {
            $this->log("Received params: " . json_encode($params, JSON_UNESCAPED_UNICODE));
        }

        //  从params获取SSRC（由gbvr-iot数据库分配）
        $ssrc = $params['ssrc'] ?? null;
        if (!$ssrc) {
            return $this->errorResponse($requestId, "Missing SSRC from API, params must include 'ssrc'");
        }

        //  从params获取ZLM端口（由gbvr-iot的ZLM服务分配）
        $rtpPort = $params['rtp_port'] ?? null;
        if (!$rtpPort) {
            return $this->errorResponse($requestId, "Missing rtp_port from API, params must include 'rtp_port'");
        }

        // TCP 模式
        $tcpMode = $params['tcp_mode'] ?? 0;

        // 可选：stream_id用于会话追踪
        $streamId = $params['stream_id'] ?? null;

        //  从params获取收流IP（由gbvr-iot根据media_server表的stream_ip传入）
        $mediaServerIp = $params['stream_ip'] ?? null;
        if (!$mediaServerIp) {
            return $this->errorResponse($requestId, 'Missing stream_ip in params');
        }

        $this->log("Media server IP: {$mediaServerIp}");

        // 构建 SDP（使用传入的SSRC和端口）
        $sdp = SdpBuilder::buildLiveVideoSdp(
            serverId: $this->config['server_id'],
            mediaIp: $mediaServerIp,  // 使用从params传入的收流IP
            mediaPort: $rtpPort,
            ssrc: $ssrc,
            tcpMode: $tcpMode,
            seniorSdp: $params['senior_sdp'] ?? false,
            streamId: $streamId,
        );

        // 调试:打印生成的SDP
        if ($this->config['debug']) {
            $this->log("Generated SDP:\n{$sdp}");
        }

        // 发送 INVITE
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $subject = "{$channelId}:{$channelId},{$this->config['server_id']}:0";

        $callId = $this->sipServer->sendInvite($targetUri, $sdp, [
            'Subject'      => $subject,
            'Content-Type' => 'application/sdp',
        ]);

        if ($callId === false) {
            return $this->errorResponse($requestId, "Failed to send INVITE");
        }

        // 保存会话信息
        //        $sessionKey = $streamId; //"{$deviceId}:{$channelId}:live"
        if (isset($this->activeSessions[$streamId])) {
            // 会话已存在，直接返回现有信息（viewer_count 由 API 层数据库管理）
            $this->log("INFO: 复用直播流 {$streamId}");
            return [
                'success'    => true,
                'request_id' => $this->activeSessions[$streamId]['request_id'],
                'call_id'    => $this->activeSessions[$streamId]['call_id'],
                'stream_id'  => $streamId,
                'reused'     => true,
            ];
        }

        $this->activeSessions[$streamId] = [
            'request_id' => $requestId,
            'call_id'    => $callId,
            'dialog_id'  => -1,  // 等待 200 OK 后更新
            'device_id'  => $deviceId,
            'channel_id' => $channelId,
            'type'       => 'live',
            'ssrc'       => $ssrc,
            'rtp_port'   => $rtpPort,
            'stream_id'  => $streamId,
            'started_at' => time(),
            // viewer_count 已移至 API 层数据库管理
        ];

        $this->log("Live video session created: {$streamId} (CallID: {$callId}, SSRC: {$ssrc}, Rtp Port: {$rtpPort})");

        return [
            'success'    => true,
            'request_id' => $requestId,
            'call_id'    => $callId,
            'ssrc'       => $ssrc,
            'rtp_port'   => $rtpPort,
            'stream_id'  => $streamId,
        ];
    }

    /**
     * 停止实时视频
     * 流程: BYE -> 200 OK
     */
    private function handleStopLiveVideo(string $requestId, string $deviceId, string $channelId, array $params) : array
    {
        $this->log("Stop live video: {$channelId}");
        if (!isset($params['stream_id'])) {
            return $this->errorResponse($requestId, "Missing stream_id in params");
        }

        $sessionKey = $params['stream_id'];
        $session = $this->activeSessions[$sessionKey] ?? null;

        if (!$session) {
            return $this->errorResponse($requestId, "No active live video session");
        }

        // 直接发送 BYE 关闭会话（viewer_count 由 API 层数据库管理）
        $dialogId = $session['dialog_id'] ?? -1;
        $result = $this->sipServer->sendBye($session['call_id'], $dialogId);

        if ($result === false) {
            $this->log("WARNING: sendBye failed (call_id={$session['call_id']}, dialog_id={$dialogId})");
        }

        // 移除会话
        unset($this->activeSessions[$sessionKey]);
        $this->log("Live video session stopped: {$sessionKey}");

        return [
            'success'    => true,
            'request_id' => $requestId,
        ];
    }

    /**
     * 开始录像回放
     * 流程: INVITE(带录像时间) -> 200 OK -> ACK
     */
    private function handleStartPlayback(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("Start playback: {$channelId}");

        // 验证参数
        if (!isset($params['start_time']) || !isset($params['end_time'])) {
            return $this->errorResponse($requestId, "Missing start_time or end_time");
        }

        $startTime = $params['start_time'];  // 格式: 2024-01-01T00:00:00
        $endTime = $params['end_time'];

        //  从params获取SSRC和ZLM端口
        $ssrc = $params['ssrc'] ?? null;
        $rtpPort = $params['rtp_port'] ?? null;

        if (!$ssrc || !$rtpPort) {
            return $this->errorResponse($requestId, "Missing ssrc or rtp_port from API");
        }

        // TCP 模式
        $tcpMode = $params['tcp_mode'] ?? 0;

        $streamId = $params['stream_id'] ?? null;

        //  从params获取收流IP
        $mediaServerIp = $params['stream_ip'] ?? null;
        if (!$mediaServerIp) {
            return $this->errorResponse($requestId, 'Missing stream_ip in params');
        }

        // 将 ISO 8601 时间转换为 NTP 时间戳（SDP t= 行使用）
        // NTP 时间戳 = Unix 时间戳 + 2208988800（NTP epoch: 1900-01-01）
        $startUnix = strtotime($startTime);
        $endUnix = strtotime($endTime);
        $startNtp = $startUnix;
        $endNtp = $endUnix;

        // 先检查是否有活跃的会话可以复用（避免重复发送 INVITE）
        if (isset($this->activeSessions[$streamId])) {
            // 会话已存在，直接返回现有信息（viewer_count 由 API 层数据库管理）
            $this->log("INFO: 复用回放流 {$streamId}");

            return [
                'success'    => true,
                'request_id' => $this->activeSessions[$streamId]['request_id'],
                'call_id'    => $this->activeSessions[$streamId]['call_id'],
                'stream_id'  => $streamId,
                'reused'     => true,
            ];
        }

        // 构建 SDP (回放必须包含时间范围)
        // GB28181 标准: 录像回放的 SDP t= 行必须使用 NTP 时间戳
        $sdp = SdpBuilder::buildPlaybackSdp(
            serverId: $this->config['server_id'],
            mediaIp: $mediaServerIp,  // 使用从params传入的收流IP
            mediaPort: $rtpPort,
            ssrc: $ssrc,
            startTime: $startNtp,  // NTP 时间戳
            endTime: $endNtp,      // NTP 时间戳
            tcpMode: $tcpMode
        );

        // 发送 INVITE（只有新会话才需要）
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $subject = "{$channelId}:{$channelId},{$this->config['server_id']}:0";

        $callId = $this->sipServer->sendInvite($targetUri, $sdp, [
            'Subject'      => $subject,
            'Content-Type' => 'application/sdp',
        ]);

        if ($callId === false) {
            return $this->errorResponse($requestId, "Failed to send INVITE");
        }

        // 保存新会话信息
        $this->activeSessions[$streamId] = [
            'request_id' => $requestId,
            'call_id'    => $callId,
            'dialog_id'  => -1,  // 等待 200 OK 后更新
            'device_id'  => $deviceId,
            'channel_id' => $channelId,
            'type'       => 'playback',
            'ssrc'       => $ssrc,
            'rtp_port'   => $rtpPort,
            'stream_id'  => $streamId,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'started_at' => time(),
            // viewer_count 已移至 API 层数据库管理
        ];

        $this->log("Playback session created: {$streamId} (CallId: {$callId})");

        return [
            'success'    => true,
            'request_id' => $requestId,
            'call_id'    => $callId,
            'ssrc'       => $ssrc,
            'rtp_port'   => $rtpPort,
            'stream_id'  => $streamId,
        ];
    }

    /**
     * 停止录像回放
     */
    private function handleStopPlayback(string $requestId, string $deviceId, string $channelId, array $params) : array
    {
        $this->log("Stop playback: {$channelId}");
        if (!isset($params['stream_id'])) {
            return $this->errorResponse($requestId, "Missing stream_id in params");
        }

        $sessionKey = $params['stream_id']; //"{$deviceId}:{$channelId}:playback";
        $session = $this->activeSessions[$sessionKey] ?? null;

        if (!$session) {
            return $this->errorResponse($requestId, "No active playback session");
        }

        // 直接发送 BYE 关闭会话（viewer_count 由 API 层管理）
        $dialogId = $session['dialog_id'] ?? -1;
        $result = $this->sipServer->sendBye($session['call_id'], $dialogId);

        if ($result === false) {
            $this->log("WARNING: sendBye failed for playback (call_id={$session['call_id']}, dialog_id={$dialogId})");
        }

        // 移除会话
        unset($this->activeSessions[$sessionKey]);
        $this->log("Playback session stopped: {$sessionKey}");

        return [
            'success'    => true,
            'request_id' => $requestId,
        ];
    }

    /**
     * 处理语音对讲邀请（INVITE）
     *
     * 流程: INVITE -> 200 OK -> ACK
     *
     * 与视频点播的区别：
     * 1. 使用 buildTalkSdp 构建音频 SDP (m=audio, Payload 8)
     * 2. mode 默认为 'recvonly' (设备接收音频，平台推流)
     * 3. 如果需要双向对讲，可传入 mode='sendrecv'
     * 4. session_type 为 'talk'
     *
     * @param string $requestId 请求ID
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $deviceIp 设备IP
     * @param int $devicePort 设备端口
     * @param array $session 会话信息
     * @return array
     */
    private function handleVoiceInvite(
        string $requestId,
        string $deviceId,
        string $channelId,
        string $deviceIp,
        int $devicePort,
        array $session
    ) : array
    {
        //        NVITE sip:34020000001320000001@192.168.1.100:5060 SIP/2.0
        //        Via: SIP/2.0/UDP 192.168.1.3:5060;rport;branch=z9hG4bK1234567890
        //        From: <sip:34020000002000000001@3402000000>;tag=abcd1234
        //        To: <sip:34020000001320000001@192.168.1.100:5060>
        //        Call-ID: 1234567890abcdef@192.168.1.3
        //        CSeq: 1 INVITE
        //        Contact: <sip:34020000002000000001@192.168.1.3:5060>
        //        Max-Forwards: 70
        //        User-Agent: WVP-PRO
        //        Subject: 34020000001320000001:0000000001,34020000002000000001:0
        //        Content-Type: APPLICATION/SDP
        //        Content-Length: 258
        //
        //        v=0
        //        o=34020000001320000001 0 0 IN IP4 192.168.1.3
        //        s=Talk
        //        c=IN IP4 192.168.1.3
        //        t=0 0
        //        m=audio 50001 TCP/RTP/AVP 8
        //        a=setup:passive
        //        a=connection:new
        //        a=sendrecv
        //        a=rtpmap:8 PCMA/8000
        //        y=0000000001
        //        f=v/////a/1/8/1
        $this->log("Start voice talk: {$channelId}");

        // 调试：打印接收到的params
        if ($this->config['debug'] ?? false) {
            $this->log("Received params: " . json_encode($session, JSON_UNESCAPED_UNICODE));
        }

        //  1. 验证必需参数：SSRC（由gbvr-iot数据库分配）
        $ssrc = $session['ssrc'] ?? null;
        if (!$ssrc) {
            return $this->errorResponse($requestId, "Missing SSRC from API, params must include 'ssrc'");
        }

        $rtpPort = $session['rtp_local_port'] ?? null;
        if (!$rtpPort) {
            return $this->errorResponse($requestId, "Missing rtp_port from API, params must include 'rtp_local_port'");
        }

        //  3. 验证必需参数：收流IP（由gbvr-iot根据media_server表的stream_ip传入）
        $mediaServerIp = $session['media_server_ip'] ?? null;
        if (!$mediaServerIp) {
            return $this->errorResponse($requestId, 'Missing media_server_ip in params');
        }

        $this->log("Media server IP: {$mediaServerIp}");

        //  4. 可选参数
        // rtp_tcp 是 ZLM 层面的布尔(0=UDP,1=TCP)，转为 SDP 层面的 tcp_mode
        $tcpMode = !empty($session['rtp_tcp']) ? 1 : 0;
        // sdp_direction 由 API 层根据应用 mode(talk/broadcast) 映射而来
        // talk -> sendrecv (双向对讲), broadcast -> recvonly (设备只接收)
        // 优先使用 sdp_direction，兼容旧版本回退到 mode（如果 mode 恰好是合法的 SDP direction）
        $mode = $session['sdp_direction'] ?? $session['mode'] ?? 'sendrecv';
        // 防御：如果 mode 仍然不是合法的 SDP direction 属性，做最终映射
        $mode = match ($mode) {
            'talk' => 'sendrecv',
            'broadcast' => 'recvonly',
            'sendrecv', 'sendonly', 'recvonly', 'inactive' => $mode,
            default => 'sendrecv',
        };
        // API 层字段名是 'stream'（不是 'stream_id'），兼容两种写法
        $streamId = $session['stream'] ?? $session['stream_id'] ?? null;

        //  6. 构建 Talk SDP（音频对讲）
        $sdp = SdpBuilder::buildTalkSdp(
            serverId: $this->config['server_id'],
            mediaIp: $mediaServerIp,
            mediaPort: $rtpPort,
            ssrc: $ssrc,
            tcpMode: $tcpMode,
            mode: $mode,  // recvonly/sendonly/sendrecv
            channelId: $channelId  // o= 行使用 channelId (与 WVP 一致)
        );

        // 调试：打印生成的SDP
        if ($this->config['debug'] ?? false) {
            $this->log("Generated Talk SDP:\n{$sdp}");
        }

        //  7. 发送 INVITE（语音对讲）
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";

        // Subject 格式：channelId:channelId,serverId:0
        $subject = "{$channelId}:{$channelId},{$this->config['server_id']}:0";

        $callId = $this->sipServer->sendInvite($targetUri, $sdp, [
            'Subject'      => $subject,
            'Content-Type' => 'application/sdp',
        ]);

        if ($callId === false) {
            return $this->errorResponse($requestId, "Failed to send INVITE for voice talk");
        }

        $this->activeSessions[$streamId] = [
            'request_id' => $requestId,
            'call_id'    => $callId,
            'dialog_id'  => -1,  // 等待 200 OK 后更新
            'device_id'  => $deviceId,
            'channel_id' => $channelId,
            'type'       => 'talk',  //  会话类型：对讲
            'ssrc'       => $ssrc,
            'rtp_port'   => $rtpPort,
            'stream_id'  => $streamId,
            'mode'       => $mode,  // 保存媒体模式
            'tcp_mode'   => $tcpMode,
            'session_id' => $session['session_id'] ?? null,  // API 层的 DB 会话 ID
            'started_at' => time(),
        ];

        $this->log("Voice talk session created: {$streamId} (CallID: {$callId}, SSRC: {$ssrc}, Port: {$rtpPort}, Mode: {$mode})");

        return [
            'success'    => true,
            'request_id' => $requestId,
            'call_id'    => $callId,
            'ssrc'       => $ssrc,
            'rtp_port'   => $rtpPort,
            'stream_id'  => $streamId,
            'mode'       => $mode,
            'tcp_mode'   => $tcpMode,
        ];
    }

    /**
     * 处理语音对讲结束（BYE）
     *
     * 流程: BYE -> 200 OK
     *
     * 与视频点播的区别：
     * - session_type 为 'talk'
     * - 需要通知 ZLM 停止音频流
     *
     * @param string $requestId 请求ID
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param array $params 参数:
     *   - stream_id: 流ID（必需，用于查找会话）
     * @return array
     */
    private function handleVoiceBye(
        string $requestId,
        string $deviceId,
        string $channelId,
        array $params
    ) : array
    {
        $this->log("Stop voice talk: {$channelId}");

        //  1. 验证必需参数：stream_id
        if (!isset($params['stream_id'])) {
            return $this->errorResponse($requestId, "Missing stream_id in params");
        }

        $streamId = $params['stream_id'];
        $session = $this->activeSessions[$streamId] ?? null;

        //  2. 检查会话是否存在
        if (!$session) {
            $this->log("WARNING: No active talk session found for stream_id={$streamId}");
            return $this->errorResponse($requestId, "No active voice talk session for stream_id={$streamId}");
        }

        //  3. 验证会话类型（talk 或 broadcast）
        if ($session['type'] !== 'talk' && $session['type'] !== 'broadcast') {
            $this->log("WARNING: Session {$streamId} is not a voice session (type={$session['type']})");
            return $this->errorResponse($requestId, "Session {$streamId} is not a voice session");
        }

        //  4. 发送 BYE 关闭 SIP 会话
        $dialogId = $session['dialog_id'] ?? -1;
        $result = $this->sipServer->sendBye($session['call_id'], $dialogId);

        if ($result === false) {
            $this->log("WARNING: sendBye failed (call_id={$session['call_id']}, dialog_id={$dialogId})");
            // 即使 BYE 失败，也继续清理会话
        }

        //  5. 移除会话
        unset($this->activeSessions[$streamId]);

        $this->log("Voice talk session stopped: {$streamId} (CallID: {$session['call_id']})");

        return [
            'success'    => true,
            'request_id' => $requestId,
            'stream_id'  => $streamId,
            'call_id'    => $session['call_id'],
        ];
    }

    /**
     * 处理语音广播命令（Broadcast 模式）
     *
     * 广播模式流程（GB28181-2016/2022）：
     * 1. 服务端发送 MESSAGE（CmdType=Broadcast）通知设备 ← 本方法完成
     * 2. 设备处理后主动发送 INVITE 给服务端
     * 3. 网关回复 200 OK（携带 SDP） ← 由 handleBroadcastInvite() 完成
     * 4. 设备 ACK
     * 5. ZLM 向设备推送音频流
     *
     * 参考 WVP-PRO: SIPCommanderFroPlatform::audioBroadcastCmd()
     */
    private function handleVoiceBroadcast(
        string $requestId,
        string $deviceId,
        string $channelId,
        string $deviceIp,
        int $devicePort,
        array $session
    ) : array
    {
        $this->log("Start voice broadcast: {$channelId}");
        $this->log("Received broadcast params: " . json_encode($session, JSON_UNESCAPED_UNICODE));

        // 1. 验证关键参数
        // 广播 MESSAGE 阶段只需要 ssrc 和 media_server_ip（用于存储到 pendingBroadcasts）
        // rtp_local_port 此时为 0 是正确的——broadcast 模式不预调 startSendRtpPassive，
        // 端口在设备 INVITE 到达后由 setupBroadcastRtp 分配
        $ssrc = $session['ssrc'] ?? null;
        $mediaServerIp = $session['media_server_ip'] ?? null;

        if (!$ssrc || !$mediaServerIp) {
            return $this->errorResponse($requestId,
                "Missing required params: ssrc={$ssrc}, media_server_ip={$mediaServerIp}");
        }

        // rtp_local_port 此阶段可能为 0，记录但不校验
        $rtpLocalPort = $session['rtp_local_port'] ?? $session['rtp_port'] ?? 0;

        $this->log("Media server IP: {$mediaServerIp}");

        $streamId = $session['stream'] ?? $session['stream_id'] ?? null;
        // sdp_direction 用于 SDP 构建（recvonly/sendrecv），app 用于 ZLM 应用名（broadcast/talk）
        $mode = $session['sdp_direction'] ?? $session['mode'] ?? 'recvonly';
        $app = $session['mode'] ?? 'broadcast'; // ZLM 应用名，不是 SDP direction
        // rtp_tcp 是 ZLM 层面的布尔(0=UDP,1=TCP)，转为 SDP 层面的 tcp_mode(0=UDP,1=TCP被动)
        $tcpMode = !empty($session['rtp_tcp']) ? 1 : 0;

        // 2. 构建 Broadcast MESSAGE XML
        $sn = rand(100000, 999999);
        $charset = $session['charset'] ?? 'GB2312';
        $broadcastXml = "<?xml version=\"1.0\" encoding=\"{$charset}\"?>\r\n";
        $broadcastXml .= "<Notify>\r\n";
        $broadcastXml .= "<CmdType>Broadcast</CmdType>\r\n";
        $broadcastXml .= "<SN>{$sn}</SN>\r\n";
        $broadcastXml .= "<SourceID>{$this->config['server_id']}</SourceID>\r\n";
        $broadcastXml .= "<TargetID>{$channelId}</TargetID>\r\n";
        $broadcastXml .= "</Notify>\r\n";

        if ($this->config['debug'] ?? false) {
            $this->log("Broadcast MESSAGE XML:\n{$broadcastXml}");
        }

        // 3. 发送 MESSAGE 到设备
        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->sipServer->sendMessage($targetUri, $broadcastXml, 'Application/MANSCDP+xml');

        if ($result === false) {
            return $this->errorResponse($requestId, "Failed to send Broadcast MESSAGE to device");
        }

        // 4. 存储待处理的广播会话（等待设备发送 INVITE）
        //    Key 使用 channelId，因为设备 INVITE 的 To URI 包含 channelId
        $this->pendingBroadcasts[$channelId] = [
            'request_id'      => $requestId,
            'device_id'       => $deviceId,
            'channel_id'      => $channelId,
            'type'            => 'broadcast',
            'ssrc'            => $ssrc,
            'rtp_port'        => $rtpLocalPort,
            'media_server_ip' => $mediaServerIp,
            'media_server_id' => $session['media_server_id'] ?? '',
            'stream_id'       => $streamId,
            'app'             => $app,       // ZLM 应用名（broadcast/talk）
            'mode'            => $mode,     // SDP direction（recvonly/sendrecv）
            'tcp_mode'        => $tcpMode,
            'session_id'      => $session['session_id'] ?? null,
            'started_at'      => time(),
        ];

        $this->log("Voice broadcast MESSAGE sent, waiting for device INVITE (Channel: {$channelId}, SSRC: {$ssrc}, Port: {$rtpLocalPort} [will be assigned on device INVITE])");

        return [
            'success'        => true,
            'request_id'     => $requestId,
            'ssrc'           => $ssrc,
            'rtp_port'       => $rtpLocalPort,
            'stream_id'      => $streamId,
            'mode'           => $mode,
            'tcp_mode'       => $tcpMode,
            'pending_invite' => true,  // 标记：等待设备 INVITE
        ];
    }

    /**
     * 查找待处理的广播会话（供 GB28181Handler 在收到设备 INVITE 时调用）
     *
     * 查找策略：
     * 1. 优先按 channelId（pendingBroadcasts 的键）查找
     * 2. 再按 device_id 字段查找（NVR 代替通道发送 INVITE 时，From URI 是 NVR device_id）
     *
     * @param string $identifier channelId 或 device_id
     * @return array|null 待处理的广播会话（含 _broadcast_key 标识实际键），不存在返回 null
     */
    public function findPendingBroadcast(string $identifier) : ?array
    {
        // 1. 直接按 channelId 查找
        if (isset($this->pendingBroadcasts[$identifier])) {
            $result = $this->pendingBroadcasts[$identifier];
            $result['_broadcast_key'] = $identifier;
            return $result;
        }

        // 2. 按 device_id 查找
        // 广播模式下，NVR 代替通道发送 INVITE，From URI 是 NVR device_id
        // 而 pendingBroadcasts 的键是 channel_id
        foreach ($this->pendingBroadcasts as $channelId => $session) {
            if ($session['device_id'] === $identifier) {
                $session['_broadcast_key'] = $channelId;
                return $session;
            }
        }

        return null;
    }

    /**
     * 移除待处理的广播会话
     *
     * 支持按 channelId（键）或 device_id（字段）移除
     */
    public function removePendingBroadcast(string $identifier) : void
    {
        // 直接按键移除
        if (isset($this->pendingBroadcasts[$identifier])) {
            unset($this->pendingBroadcasts[$identifier]);
            return;
        }

        // 按 device_id 字段查找并移除
        foreach ($this->pendingBroadcasts as $channelId => $session) {
            if ($session['device_id'] === $identifier) {
                unset($this->pendingBroadcasts[$channelId]);
                return;
            }
        }
    }

    /**
     * 添加活跃会话（供 GB28181Handler 在处理设备发起的 INVITE 时调用）
     *
     * 广播模式下，设备发送 INVITE 给服务端，由 GB28181Handler 直接回复 200 OK。
     * 此时需要将会话注册到 activeSessions，以便后续 voice_bye 命令能找到并发送 BYE。
     *
     * @param string $streamId 流ID（会话键）
     * @param array $session 会话数据
     */
    public function addActiveSession(string $streamId, array $session) : void
    {
        // 确保 started_at 存在
        if (!isset($session['started_at'])) {
            $session['started_at'] = time();
        }

        $this->activeSessions[$streamId] = $session;
        $this->log("Active session added: {$streamId} (type: {$session['type']}, call_id: {$session['call_id']})");
    }

    /**
     * 清理超时的待处理广播会话（定时器调用）
     */
    public function cleanExpiredBroadcasts(int $timeoutSeconds = 30) : void
    {
        $now = time();
        foreach ($this->pendingBroadcasts as $channelId => $session) {
            if ($now - $session['started_at'] > $timeoutSeconds) {
                $this->log("Broadcast session expired: {$channelId}");
                unset($this->pendingBroadcasts[$channelId]);
            }
        }
    }

    /**
     * 清理超时的待处理订阅请求（定时器调用）
     *
     * 设备长时间不回复 SUBSCRIBE 的 200 OK 时，清理 orphaned 条目防止内存泄漏
     */
    public function cleanExpiredPendingSubscribes(int $timeoutSeconds = 120) : void
    {
        $now = time();
        foreach ($this->pendingSubscribes as $subscriptionId => $ctx) {
            if (isset($ctx['created_at']) && ($now - $ctx['created_at'] > $timeoutSeconds)) {
                $this->log("Pending subscribe expired: subId={$subscriptionId}, event=" . ($ctx['event_type'] ?? 'unknown'), 'WARNING');
                unset($this->pendingSubscribes[$subscriptionId]);
            }
        }
    }


    /**
     * 查询设备信息
     */
    private function handleQueryDeviceInfo(string $requestId, string $deviceId, string $deviceIp, int $devicePort) : array
    {
        $this->log("Query device info: {$deviceId}");

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->queryDeviceInfo($targetUri, $deviceId);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Query sent' : 'Failed to send query',
        ];
    }

    /**
     * 查询设备状态
     */
    private function handleQueryDeviceStatus(string $requestId, string $deviceId, string $deviceIp, int $devicePort) : array
    {
        $this->log("Query device status: {$deviceId}");

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->queryDeviceStatus($targetUri, $deviceId);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Query sent' : 'Failed to send query',
        ];
    }

    /**
     * 查询录像文件
     */
    private function handleQueryRecord(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("Query record: {$channelId}");

        // 验证参数
        if (!isset($params['start_time']) || !isset($params['end_time'])) {
            return $this->errorResponse($requestId, "Missing start_time or end_time");
        }

        $startTime = $params['start_time'];  // 格式: 2024-01-01T00:00:00
        $endTime = $params['end_time'];
        $type = $params['type'] ?? 'all';  // all, time, alarm, manual

        //        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->queryRecordInfo($targetUri, $channelId, $startTime, $endTime, $type);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Query sent' : 'Failed to send query',
        ];
    }

    /**
     * 录像回放控制（会话内 SIP INFO 命令）
     *
     * 要修复：回放控制必须使用 SIP INFO（不是 MESSAGE）
     * 必须在已建立的 INVITE 会话内发送，需要 dialog_id
     *
     * 支持的操作:
     * - play/resume: 正常播放/恢复
     * - pause: 暂停
     * - seek: 拖动到指定时间
     * - scale/speed: 倍速播放（0.5x, 1.0x, 2.0x, 4.0x）
     */
    private function handlePlaybackControl(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("Playback control: {$channelId}");

        // 验证参数
        if (!isset($params['action'])) {
            return $this->errorResponse($requestId, "Missing action parameter");
        }

        if (!isset($params['stream_id'])) {
            return $this->errorResponse($requestId, "Missing stream_id parameter (required to find active session)");
        }

        $action = $params['action'];
        $streamId = $params['stream_id'];

        // 查找活跃的回放会话
        $session = $this->activeSessions[$streamId] ?? null;
        if (!$session) {
            return $this->errorResponse($requestId, "No active playback session found: {$streamId}");
        }

        if ($session['type'] !== 'playback') {
            return $this->errorResponse($requestId, "Session is not a playback session: {$session['type']}");
        }

        $dialogId = $session['dialog_id'];
        if ($dialogId <= 0) {
            return $this->errorResponse($requestId, "Session dialog_id not ready (waiting for 200 OK?)");
        }

        // 构建控制选项
        $options = [];

        switch ($action) {
            case 'play':
                // 恢复播放：从暂停位置以原倍速恢复（GB28181 B.2.1）
                // PLAY RTSP/1.0 + Range: npt=now-
                $options['range'] = 'npt=now-';
                $action = 'play';  // 统一为 play
                break;

            case 'pause':
                // 暂停播放：停止在当前位置（GB28181 B.2.2）
                // PAUSE RTSP/1.0 + PauseTime: now
                // 不需要额外参数，QuerySender 会自动添加 PauseTime
                break;

            case 'teardown':
                // 停止播放：结束会话并释放资源（GB28181 B.2.5）
                // TEARDOWN RTSP/1.0
                $action = 'teardown';
                break;

            case 'seek':
                // 随机拖放：跳转到指定时间点（GB28181 B.2.4）
                // PLAY RTSP/1.0 + Range: npt=100-（不携带 Scale）
                $seekTime = $params['seek_time'] ?? null;
                if ($seekTime === null) {
                    return $this->errorResponse($requestId, "Missing seek_time for seek action");
                }

                // 如果是时间字符串，转换为秒数
                if (is_string($seekTime) && str_contains($seekTime, ':')) {
                    // 格式：HH:MM:SS 或 MM:SS
                    $timeParts = explode(':', $seekTime);
                    if (count($timeParts) === 3) {
                        $seconds = $timeParts[0] * 3600 + $timeParts[1] * 60 + $timeParts[2];
                    } else {
                        $seconds = $timeParts[0] * 60 + $timeParts[1];
                    }
                } else {
                    $seconds = floatval($seekTime);
                }

                $seconds = abs($seconds);
                $options['range'] = "npt={$seconds}-";
                break;

            case 'scale':
                // 倍速播放：只携带 Scale 头（GB28181 B.2.3）
                // PLAY RTSP/1.0 + Scale: 2.0（不携带 Range）
                $scale = $params['scale'] ?? $params['speed'] ?? null;
                if ($scale === null) {
                    return $this->errorResponse($requestId, "Missing scale/speed parameter");
                }
                $options['range'] = "npt=now-";
                $options['scale'] = sprintf("%.6f", $scale);//floatval($scale);
                $action = 'scale';
                break;

            case 'reverse':
                // 倒放：负倍速播放（GB28181 B.2.8）
                // PLAY RTSP/1.0 + Scale: -1.0
                $speed = $params['speed'] ?? 1;
                $options['scale'] = -floatval($speed);
                $action = 'scale';
                break;

            case 'seek_and_play':
                // 跳转并播放：同时指定位置和倍速
                // PLAY RTSP/1.0 + Range: npt=100- + Scale: 2.0
                $seekTime = $params['seek_time'] ?? null;
                if ($seekTime === null) {
                    return $this->errorResponse($requestId, "Missing seek_time for seek_and_play action");
                }

                $seconds = is_numeric($seekTime) ? floatval($seekTime) : 0;
                $options['range'] = "npt={$seconds}-";

                // 可选：同时指定倍速
                if (isset($params['speed']) || isset($params['scale'])) {
                    $scale = $params['scale'] ?? $params['speed'] ?? 1.0;
                    $options['scale'] = sprintf("%.6f", $scale);
                    //                    $options['scale'] = floatval($scale);
                }
                $action = 'play';  // 使用 play 方法
                break;

            default:
                return $this->errorResponse($requestId, "Unknown playback action: {$action}");
        }

        // 发送控制命令（使用修复后的 QuerySender::playbackControl）
        $result = $this->querySender->playbackControl($dialogId, $action, $options);

        $this->log("Playback control sent: action={$action}, dialog_id={$dialogId}, result=" . ($result ? 'success' : 'failed'));

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Playback control command sent' : 'Failed to send command',
            'action'     => $action,
            'dialog_id'  => $dialogId,
            'options'    => $options,
        ];
    }

    /**
     * 录像下载
     *
     * 流程:
     * 1. 发送 INVITE (Download SDP)
     * 2. 设备推流到 ZLM
     * 3. ZLM 录制成文件
     *
     * 注意: 与普通回放的区别是 session_name = 'Download'
     */
    private function handleDownloadRecord(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("Download record: {$channelId}");

        // 验证参数（与 startPlayback 相同）
        if (!isset($params['start_time']) || !isset($params['end_time'])) {
            return $this->errorResponse($requestId, "Missing start_time or end_time");
        }

        $startTime = $params['start_time'];
        $endTime = $params['end_time'];
        $ssrc = $params['ssrc'] ?? null;
        $rtpPort = $params['rtp_port'] ?? null;
        $streamId = $params['stream_id'] ?? null;
        $mediaServerIp = $params['stream_ip'] ?? null;
        $tcpMode = $params['tcp_mode'] ?? 0;

        if (!$ssrc || !$rtpPort || !$streamId || !$mediaServerIp) {
            return $this->errorResponse($requestId, "Missing required params: ssrc, rtp_port, stream_id, stream_ip");
        }

        // 转换时间为 NTP 时间戳
        $startUnix = strtotime($startTime);
        $endUnix = strtotime($endTime);
        $startNtp = $startUnix;
        $endNtp = $endUnix;

        // 构建下载 SDP（session_name 为 Download）
        $sdp = SdpBuilder::buildDownloadSdp(
            serverId: $this->config['server_id'],
            mediaIp: $mediaServerIp,
            mediaPort: $rtpPort,
            ssrc: $ssrc,
            startTime: $startNtp,
            endTime: $endNtp,
            tcpMode: $tcpMode
        );

        // 发送 INVITE
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $subject = "{$channelId}:{$channelId},{$this->config['server_id']}:0";

        $callId = $this->sipServer->sendInvite($targetUri, $sdp, [
            'Subject'      => $subject,
            'Content-Type' => 'application/sdp',
        ]);

        if ($callId === false) {
            return $this->errorResponse($requestId, "Failed to send INVITE for download");
        }

        // 保存下载会话
        $this->activeSessions[$streamId] = [
            'request_id' => $requestId,
            'call_id'    => $callId,
            'dialog_id'  => -1,
            'device_id'  => $deviceId,
            'channel_id' => $channelId,
            'type'       => 'download',
            'ssrc'       => $ssrc,
            'rtp_port'   => $rtpPort,
            'stream_id'  => $streamId,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'started_at' => time(),
        ];

        $this->log("Download session created: {$streamId} (CallId: {$callId})");

        return [
            'success'     => true,
            'request_id'  => $requestId,
            'call_id'     => $callId,
            'ssrc'        => $ssrc,
            'rtp_port'    => $rtpPort,
            'stream_id'   => $streamId,
            'stream_type' => 'download',
        ];
    }

    /**
     * PTZ 云台控制
     */
    private function handlePtzControl(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("PTZ control: {$channelId}");

        // 验证参数
        if (!isset($params['command'])) {
            return $this->errorResponse($requestId, "Missing PTZ command");
        }

        $ptzCmd = $this->buildPtzCommand($params);

        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'PTZ command sent' : 'Failed to send PTZ command',
        ];
    }

    /**
     * 查询设备目录
     */
    private function handleQueryCatalog(string $requestId, string $deviceId, string $deviceIp, int $devicePort) : array
    {
        $this->log("Query catalog: {$deviceId}");

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->queryCatalog($targetUri, $deviceId);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Query sent' : 'Failed to send query',
        ];
    }

    /**
     * 构建 PTZ 控制命令
     * GB28181 PTZ 命令格式: A5 0F 01 [指令码] [水平速度] [垂直速度] [焦距/光圈速度] [校验码]
     *
     * 指令码(第4字节)位定义:
     * bit 0 (0x01): 右移
     * bit 1 (0x02): 左移
     * bit 2 (0x04): 下移
     * bit 3 (0x08): 上移
     * bit 4 (0x10): 变倍放大 (zoom_in)
     * bit 5 (0x20): 变倍缩小 (zoom_out)
     * bit 6 (0x40): 对焦远 (focus_far)
     * bit 7 (0x80): 对焦近 (focus_near)
     *
     * 第7字节组合:
     * - 高4位: 变倍速度
     * - 低4位: 光圈/对焦速度
     *
     * 单独命令(不使用指令码位):
     * - 0x42: 对焦近 (focus_near)
     * - 0x41: 对焦远 (focus_far)
     * - 0x44: 光圈开大 (iris_open)
     * - 0x48: 光圈缩小 (iris_close)
     *
     * 参考: GB/T 28181-2016 附录F, ak-stream/SipServer.cs GetPtzCmd()
     */
    private function buildPtzCommand(array $params) : string
    {
        $command = $params['command'];  // up, down, left, right, zoom_in, zoom_out, focus_near, focus_far, iris_open, iris_close, stop
        $speed = $params['speed'] ?? 5;  // 1-255

        $cmdCode = 0;
        $horizontalSpeed = 0;
        $verticalSpeed = 0;
        $zoomSpeed = 0;
        $focusIrisSpeed = 0;

        switch ($command) {
            case 'right':
                $cmdCode = 0x01;  // bit 0: 右移
                $horizontalSpeed = $speed;
                break;
            case 'left':
                $cmdCode = 0x02;  // bit 1: 左移
                $horizontalSpeed = $speed;
                break;
            case 'down':
                $cmdCode = 0x04;  // bit 2: 下移
                $verticalSpeed = $speed;
                break;
            case 'up':
                $cmdCode = 0x08;  // bit 3: 上移
                $verticalSpeed = $speed;
                break;
            case 'zoom_in':
                $cmdCode = 0x10;  // bit 4: 放大
                $zoomSpeed = $speed & 0x0F;  // 焦距速度(第7字节高4位)
                break;
            case 'zoom_out':
                $cmdCode = 0x20;  // bit 5: 缩小
                $zoomSpeed = $speed & 0x0F;
                break;
            case 'focus_near':
                // 对焦近 (聚焦+)
                $cmdCode = 0x42;
                $focusIrisSpeed = $speed & 0x0F;
                break;
            case 'focus_far':
                // 对焦远 (聚焦-)
                $cmdCode = 0x41;
                $focusIrisSpeed = $speed & 0x0F;
                break;
            case 'iris_open':
                // 光圈开大
                $cmdCode = 0x44;
                $focusIrisSpeed = $speed & 0x0F;
                break;
            case 'iris_close':
                // 光圈缩小
                $cmdCode = 0x48;
                $focusIrisSpeed = $speed & 0x0F;
                break;
            case 'stop':
                // 全部为0: 停止所有动作
                break;
        }

        // 第7字节: 高4位是变倍速度, 低4位是对焦/光圈速度
        $byte7 = (($zoomSpeed << 4) & 0xF0) | ($focusIrisSpeed & 0x0F);

        // 计算校验码: (字节1 + 字节2 + ... + 字节7) % 256
        $checksum = (0xA5 + 0x0F + 0x01 + $cmdCode + $horizontalSpeed + $verticalSpeed + $byte7) % 0x100;

        // 构建完整命令: A5 0F 01 [指令码] [水平速度] [垂直速度] [焦距速度]0 [校验码]
        $ptzCmd = sprintf("A50F01%02X%02X%02X%02X%02X",
            $cmdCode,
            $horizontalSpeed,
            $verticalSpeed,
            $byte7,
            $checksum
        );

        return $ptzCmd;
    }

    /**
     * 构建预置位命令
     *
     * GB28181 预置位命令:
     * - 0x81: 设置预置位
     * - 0x82: 调用预置位
     * - 0x83: 删除预置位
     *
     * 格式: A5 0F 01 [指令码] 00 [预置位编号] 00 [校验码]
     */
    private function buildPresetCommand(string $action, int $presetId) : string
    {
        $cmdCode = match ($action) {
            'set' => 0x81,     // 设置预置位
            'call' => 0x82,    // 调用预置位
            'delete' => 0x83,  // 删除预置位
            default => 0x00
        };

        // 预置位ID范围: 1-255
        $presetId = max(1, min(255, $presetId));

        // 计算校验码
        $checksum = (0xA5 + 0x0F + 0x01 + $cmdCode + 0x00 + $presetId + 0x00) % 0x100;

        // 构建命令: A5 0F 01 [cmdCode] 00 [presetId] 00 [checksum]
        $presetCmd = sprintf("A50F01%02X00%02X00%02X", $cmdCode, $presetId, $checksum);

        return $presetCmd;
    }

    /**
     * 构建巡航命令
     * GB28181 巡航命令格式: A5 0F 01 [指令码] [巡航组号] [预置位号/参数低8位] [参数高4位] [校验码]
     *
     * 指令码(第4字节):
     * - 0x84: 添加巡航点 (AddCruisePoint)
     * - 0x85: 删除巡航点 (DeleteCruisePoint)
     * - 0x86: 设置巡航速度 (SetCruiseSpeed) - 参数范围 1-4095
     * - 0x87: 设置巡航时间 (SetCruiseDuration) - 参数范围 1-4095 秒
     * - 0x88: 开始巡航 (StartCruise)
     *
     * 注意事项:
     * 1. 巡航必须基于预置位（需要先设置预置位）
     * 2. 速度和时间参数使用12位编码（字节6低8位 + 字节7高4位）
     * 3. 字节5: 巡航组号 (0-255)
     * 4. 字节6: 预置位编号 或 参数低8位
     * 5. 字节7: 参数高4位 或 0x00
     *
     * 参考: GB/T 28181-2016, ak-stream 实现, 天翼云文档
     */
    private function buildCruiseCommand(string $action, int $groupId, int $param = 0) : string
    {
        $cmdCode = match ($action) {
            'add_point' => 0x84,      // 添加巡航点
            'delete_point' => 0x85,   // 删除巡航点
            'set_speed' => 0x86,      // 设置巡航速度
            'set_duration' => 0x87,   // 设置巡航时间
            'start' => 0x88,          // 开始巡航
            default => 0x00
        };

        // 巡航组号范围: 0-255
        $groupId = max(0, min(255, $groupId));

        // 参数编码（12位：字节6低8位 + 字节7高4位）
        if (in_array($action, ['set_speed', 'set_duration'])) {
            // 速度/时间范围: 1-4095 (0x001-0xFFF)
            $param = max(1, min(4095, $param));
            $byte6 = $param & 0xFF;           // 低8位
            $byte7 = ($param >> 8) & 0x0F;    // 高4位
        } else {
            // 添加/删除巡航点：byte6 = 预置位编号, byte7 = 0x00
            $byte6 = max(1, min(255, $param));  // 预置位编号 1-255
            $byte7 = 0x00;
        }

        // 计算校验码
        $checksum = (0xA5 + 0x0F + 0x01 + $cmdCode + $groupId + $byte6 + $byte7) % 0x100;

        // 构建命令: A5 0F 01 [cmdCode] [groupId] [byte6] [byte7] [checksum]
        $cruiseCmd = sprintf("A50F01%02X%02X%02X%02X%02X", $cmdCode, $groupId, $byte6, $byte7, $checksum);

        return $cruiseCmd;
    }

    /**
     * 设置预置位
     */
    private function handlePresetSet(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $presetId = $params['preset_id'] ?? 1;
        $this->log("Set preset: {$channelId}, preset_id={$presetId}");

        $ptzCmd = $this->buildPresetCommand('set', $presetId);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result, // ? 'Preset set command sent' : 'Failed to send preset set command',
            'preset_id'  => $presetId,
        ];
    }

    /**
     * 调用预置位
     */
    private function handlePresetCall(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $presetId = $params['preset_id'] ?? 1;
        $this->log("Call preset: {$channelId}, preset_id={$presetId}");

        $ptzCmd = $this->buildPresetCommand('call', $presetId);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Preset call command sent' : 'Failed to send preset call command',
            'preset_id'  => $presetId,
        ];
    }

    /**
     * 删除预置位
     */
    private function handlePresetDelete(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $presetId = $params['preset_id'] ?? 1;
        $this->log("Delete preset: {$channelId}, preset_id={$presetId}");

        $ptzCmd = $this->buildPresetCommand('delete', $presetId);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Preset delete command sent' : 'Failed to send preset delete command',
            'preset_id'  => $presetId,
        ];
    }

    /**
     * 添加巡航点
     */
    private function handleCruiseAddPoint(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $groupId = $params['group_id'] ?? 0;
        $presetId = $params['preset_id'] ?? 1;
        $this->log("Add cruise point: {$channelId}, group={$groupId}, preset={$presetId}");

        $ptzCmd = $this->buildCruiseCommand('add_point', $groupId, $presetId);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Cruise point added' : 'Failed to add cruise point',
            'group_id'   => $groupId,
            'preset_id'  => $presetId,
        ];
    }

    /**
     * 删除巡航点
     */
    private function handleCruiseDeletePoint(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $groupId = $params['group_id'] ?? 0;
        $presetId = $params['preset_id'] ?? 1;
        $this->log("Delete cruise point: {$channelId}, group={$groupId}, preset={$presetId}");

        $ptzCmd = $this->buildCruiseCommand('delete_point', $groupId, $presetId);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Cruise point deleted' : 'Failed to delete cruise point',
            'group_id'   => $groupId,
            'preset_id'  => $presetId,
        ];
    }

    /**
     * 设置巡航速度
     */
    private function handleCruiseSetSpeed(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $groupId = $params['group_id'] ?? 0;
        $speed = $params['speed'] ?? 50;  // 1-4095
        $this->log("Set cruise speed: {$channelId}, group={$groupId}, speed={$speed}");

        $ptzCmd = $this->buildCruiseCommand('set_speed', $groupId, $speed);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Cruise speed set' : 'Failed to set cruise speed',
            'group_id'   => $groupId,
            'speed'      => $speed,
        ];
    }

    /**
     * 设置巡航停留时间
     */
    private function handleCruiseSetDuration(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $groupId = $params['group_id'] ?? 0;
        $duration = $params['duration'] ?? 10;  // 1-4095 秒
        $this->log("Set cruise duration: {$channelId}, group={$groupId}, duration={$duration}s");

        $ptzCmd = $this->buildCruiseCommand('set_duration', $groupId, $duration);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Cruise duration set' : 'Failed to set cruise duration',
            'group_id'   => $groupId,
            'duration'   => $duration,
        ];
    }

    /**
     * 开始巡航
     */
    private function handleCruiseStart(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $groupId = $params['group_id'] ?? 0;
        $this->log("Start cruise: {$channelId}, group={$groupId}");

        $ptzCmd = $this->buildCruiseCommand('start', $groupId, 0);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Cruise started' : 'Failed to start cruise',
            'group_id'   => $groupId,
        ];
    }

    /**
     * 停止巡航（使用PTZ停止命令）
     */
    private function handleCruiseStop(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("Stop cruise: {$channelId}");

        // 停止巡航使用普通的PTZ停止命令
        $ptzCmd = $this->buildPtzCommand(['command' => 'stop']);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Cruise stopped' : 'Failed to stop cruise',
        ];
    }

    /**
     * GB28181-2022: 设备升级
     */
    private function handleDeviceUpgrade(string $requestId, string $deviceId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("Device upgrade: {$deviceId}");

        $manufacturer = $params['manufacturer'] ?? '';
        $firmware = $params['firmware'] ?? '';

        if (!$manufacturer || !$firmware) {
            return $this->errorResponse($requestId, 'Missing manufacturer or firmware');
        }

        // 构建升级XML
        $sn = rand(1, 99999999);
        $xml = "<?xml version=\"1.0\" encoding=\"GB2312\"?>\r\n";
        $xml .= "<Control>\r\n";
        $xml .= "<CmdType>DeviceControl</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$deviceId}</DeviceID>\r\n";
        $xml .= "<DeviceUpgrade>\r\n";
        $xml .= "<Manufacturer>{$manufacturer}</Manufacturer>\r\n";
        $xml .= "<Firmware>{$firmware}</Firmware>\r\n";
        $xml .= "</DeviceUpgrade>\r\n";
        $xml .= "</Control>";

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Upgrade command sent' : 'Failed to send upgrade command',
            'sn'         => $sn,
        ];
    }

    /**
     * GB28181-2022: 图像抓拍
     */
    private function handleSnapshot(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("Snapshot: {$channelId}");

        $sessionId = $params['session_id'] ?? strtoupper(md5(uniqid()));
        $imageFormat = $params['image_format'] ?? 'JPEG';

        // 构建抓拍XML
        $sn = rand(1, 99999999);
        $xml = "<?xml version=\"1.0\" encoding=\"GB2312\"?>\r\n";
        $xml .= "<Control>\r\n";
        $xml .= "<CmdType>DeviceControl</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$channelId}</DeviceID>\r\n";
        $xml .= "<Snapshot>\r\n";
        $xml .= "<SessionID>{$sessionId}</SessionID>\r\n";
        $xml .= "<ImageFormat>{$imageFormat}</ImageFormat>\r\n";
        $xml .= "</Snapshot>\r\n";
        $xml .= "</Control>";

        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result,
            'request_id' => $requestId,
            'message'    => $result ? 'Snapshot command sent' : 'Failed to send snapshot command',
            'session_id' => $sessionId,
            'sn'         => $sn,
        ];
    }

    // ========== Phase 1: 简单设备控制命令 ==========

    /**
     * 远程重启
     */
    private function handleTeleBoot(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("TeleBoot: {$channelId}");
        $charset = $this->deviceManager->getDeviceCharset($deviceId);
        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $xml = $this->querySender->buildControlXmlPublic('DeviceControl', $channelId, ['TeleBoot' => 'Boot'], $charset);
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? 'TeleBoot command sent' : 'Failed to send TeleBoot command',
        ];
    }

    /**
     * 录像控制
     */
    private function handleRecordCmd(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $action = $params['action'] ?? 'Record'; // Record / StopRecord
        $this->log("RecordCmd: {$channelId}, action={$action}");
        $charset = $this->deviceManager->getDeviceCharset($deviceId);
        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $xml = $this->querySender->buildControlXmlPublic('DeviceControl', $channelId, ['RecordCmd' => $action], $charset);
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? "RecordCmd({$action}) sent" : "Failed to send RecordCmd",
        ];
    }

    /**
     * 布防/撤防
     */
    private function handleGuardCmd(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $action = $params['action'] ?? 'SetGuard'; // SetGuard / ResetGuard
        $this->log("GuardCmd: {$channelId}, action={$action}");
        $charset = $this->deviceManager->getDeviceCharset($deviceId);
        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $xml = $this->querySender->buildControlXmlPublic('DeviceControl', $channelId, ['GuardCmd' => $action], $charset);
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? "GuardCmd({$action}) sent" : "Failed to send GuardCmd",
        ];
    }

    /**
     * 报警复位
     */
    private function handleAlarmReset(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("AlarmReset: {$channelId}");
        $charset = $this->deviceManager->getDeviceCharset($deviceId);
        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";

        // 构建 AlarmCmd XML（可能包含 Info 子元素）
        $sn = rand(1, 99999999);
        $encoding = $charset ? : 'GB2312';
        $xml = "<?xml version=\"1.0\" encoding=\"{$encoding}\"?>\r\n";
        $xml .= "<Control>\r\n";
        $xml .= "<CmdType>DeviceControl</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$channelId}</DeviceID>\r\n";
        $xml .= "<AlarmCmd>ResetAlarm</AlarmCmd>\r\n";

        // 可选的 Info 子元素
        $alarmMethod = $params['alarm_method'] ?? null;
        $alarmType = $params['alarm_type'] ?? null;
        if ($alarmMethod !== null || $alarmType !== null) {
            $xml .= "<Info>\r\n";
            if ($alarmMethod !== null) {
                $xml .= "<AlarmMethod>{$alarmMethod}</AlarmMethod>\r\n";
            }
            if ($alarmType !== null) {
                $xml .= "<AlarmType>{$alarmType}</AlarmType>\r\n";
            }
            $xml .= "</Info>\r\n";
        }

        $xml .= "</Control>";

        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? 'AlarmReset command sent' : 'Failed to send AlarmReset command',
        ];
    }

    /**
     * 强制关键帧
     */
    private function handleIFrameCmd(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("IFrameCmd: {$channelId}");
        $charset = $this->deviceManager->getDeviceCharset($deviceId);
        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $xml = $this->querySender->buildControlXmlPublic('DeviceControl', $channelId, ['IFameCmd' => 'Send'], $charset);
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? 'IFrameCmd sent' : 'Failed to send IFrameCmd',
        ];
    }

    // ========== Phase 2: 复合设备控制命令 ==========

    /**
     * 看守位控制
     */
    private function handleHomePosition(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $enabled = $params['enabled'] ?? 1;
        $resetTime = $params['reset_time'] ?? 0;
        $presetIndex = $params['preset_index'] ?? 1;
        $this->log("HomePosition: {$channelId}, enabled={$enabled}, resetTime={$resetTime}, presetIndex={$presetIndex}");

        $charset = $this->deviceManager->getDeviceCharset($deviceId);
        $encoding = $charset ? : 'GB2312';
        $sn = rand(1, 99999999);

        $xml = "<?xml version=\"1.0\" encoding=\"{$encoding}\"?>\r\n";
        $xml .= "<Control>\r\n";
        $xml .= "<CmdType>DeviceControl</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$channelId}</DeviceID>\r\n";
        $xml .= "<HomePosition>\r\n";
        $xml .= "<Enabled>{$enabled}</Enabled>\r\n";
        $xml .= "<ResetTime>{$resetTime}</ResetTime>\r\n";
        $xml .= "<PresetIndex>{$presetIndex}</PresetIndex>\r\n";
        $xml .= "</HomePosition>\r\n";
        $xml .= "</Control>";

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? 'HomePosition command sent' : 'Failed to send HomePosition command',
        ];
    }

    /**
     * 拖拽变倍
     */
    private function handleDragZoom(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $type = $params['type'] ?? 'in'; // in / out
        $length = $params['length'] ?? 0;
        $width = $params['width'] ?? 0;
        $midPointX = $params['mid_point_x'] ?? 0;
        $midPointY = $params['mid_point_y'] ?? 0;
        $lengthX = $params['length_x'] ?? 0;
        $lengthY = $params['length_y'] ?? 0;
        $this->log("DragZoom: {$channelId}, type={$type}");

        $charset = $this->deviceManager->getDeviceCharset($deviceId);
        $encoding = $charset ? : 'GB2312';
        $sn = rand(1, 99999999);
        $tagName = ($type === 'out') ? 'DragZoomOut' : 'DragZoomIn';

        $xml = "<?xml version=\"1.0\" encoding=\"{$encoding}\"?>\r\n";
        $xml .= "<Control>\r\n";
        $xml .= "<CmdType>DeviceControl</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$channelId}</DeviceID>\r\n";
        $xml .= "<{$tagName}>\r\n";
        $xml .= "<Length>{$length}</Length>\r\n";
        $xml .= "<Width>{$width}</Width>\r\n";
        $xml .= "<MidPointX>{$midPointX}</MidPointX>\r\n";
        $xml .= "<MidPointY>{$midPointY}</MidPointY>\r\n";
        $xml .= "<LengthX>{$lengthX}</LengthX>\r\n";
        $xml .= "<LengthY>{$lengthY}</LengthY>\r\n";
        $xml .= "</{$tagName}>\r\n";
        $xml .= "</Control>";

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? "DragZoom({$type}) command sent" : "Failed to send DragZoom command",
        ];
    }

    /**
     * 设备基础配置
     */
    private function handleDeviceConfig(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("DeviceConfig: {$channelId}");

        $charset = $this->deviceManager->getDeviceCharset($deviceId);
        $encoding = $charset ? : 'GB2312';
        $sn = rand(1, 99999999);

        $xml = "<?xml version=\"1.0\" encoding=\"{$encoding}\"?>\r\n";
        $xml .= "<Control>\r\n";
        $xml .= "<CmdType>DeviceConfig</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$channelId}</DeviceID>\r\n";
        $xml .= "<BasicParam>\r\n";
        if (isset($params['name'])) {
            $xml .= "<Name>{$params['name']}</Name>\r\n";
        }
        if (isset($params['expiration'])) {
            $xml .= "<Expiration>{$params['expiration']}</Expiration>\r\n";
        }
        if (isset($params['heartbeat_interval'])) {
            $xml .= "<HeartBeatInterval>{$params['heartbeat_interval']}</HeartBeatInterval>\r\n";
        }
        if (isset($params['heartbeat_count'])) {
            $xml .= "<HeartBeatCount>{$params['heartbeat_count']}</HeartBeatCount>\r\n";
        }
        $xml .= "</BasicParam>\r\n";
        $xml .= "</Control>";

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? 'DeviceConfig command sent' : 'Failed to send DeviceConfig command',
        ];
    }

    // ========== Phase 3: 前端扩展指令 (FrontEndCommand) ==========

    /**
     * 构建前端扩展指令 (FrontEndCommand)
     *
     * 协议格式: A5 0F 01 [CmdCode] [Param1] [Param2] [CombineCode2<<4] [Checksum]
     * 校验码: (0xA5 + 0x0F + 0x01 + CmdCode + Param1 + Param2 + (CombineCode2<<4)) % 0x100
     */
    private function buildFrontEndCommand(int $cmdCode, int $param1 = 0, int $param2 = 0, int $combineCode2 = 0) : string
    {
        $byte7 = ($combineCode2 << 4) & 0xF0;
        $checksum = (0xA5 + 0x0F + 0x01 + $cmdCode + $param1 + $param2 + $byte7) % 0x100;

        return sprintf("A50F01%02X%02X%02X%02X%02X", $cmdCode, $param1, $param2, $byte7, $checksum);
    }

    /**
     * 通用前端扩展指令处理
     */
    private function handleFrontEndCmd(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, int $cmdCode, array $params) : array
    {
        $param1 = $params['group_id'] ?? $params['switch_id'] ?? $params['param1'] ?? 0;
        $param2 = $params['speed'] ?? $params['param2'] ?? 0;
        $combineCode2 = $params['combine_code2'] ?? 0;

        $this->log("FrontEndCmd: {$channelId}, cmdCode=0x" . dechex($cmdCode) . ", param1={$param1}, param2={$param2}");

        $ptzCmd = $this->buildFrontEndCommand($cmdCode, $param1, $param2, $combineCode2);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? 'FrontEndCommand sent' : 'Failed to send FrontEndCommand',
            'cmd_code'   => sprintf('0x%02X', $cmdCode),
        ];
    }

    // ========== Phase 4: 设备查询增强 ==========

    /**
     * 设备预置位查询
     */
    private function handlePresetQuery(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $this->log("PresetQuery: {$channelId}");
        $charset = $this->deviceManager->getDeviceCharset($deviceId);
        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $xml = $this->querySender->buildQueryXmlPublic('PresetQuery', $channelId, $charset);
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? 'PresetQuery sent' : 'Failed to send PresetQuery',
        ];
    }

    /**
     * 设备配置查询
     */
    private function handleConfigDownload(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params) : array
    {
        $configType = $params['config_type'] ?? 'BasicParam';
        $this->log("ConfigDownload: {$channelId}, configType={$configType}");

        $charset = $this->deviceManager->getDeviceCharset($deviceId);
        $encoding = $charset ? : 'GB2312';
        $sn = rand(1, 99999999);

        $xml = "<?xml version=\"1.0\" encoding=\"{$encoding}\"?>\r\n";
        $xml .= "<Query>\r\n";
        $xml .= "<CmdType>ConfigDownload</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$channelId}</DeviceID>\r\n";
        $xml .= "<ConfigType>{$configType}</ConfigType>\r\n";
        $xml .= "</Query>";

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success'    => $result !== false,
            'request_id' => $requestId,
            'message'    => $result !== false ? 'ConfigDownload query sent' : 'Failed to send ConfigDownload query',
        ];
    }

    private function successResponse(string $requestId, array $data) : array
    {
        return [
            'success'    => true,
            'request_id' => $requestId,
            'data'       => $data,
        ];
    }

    /**
     * 错误响应
     */
    private function errorResponse(string $requestId, string $error) : array
    {
        $this->log("Error: {$error}", 'ERROR');
        return [
            'success'    => false,
            'request_id' => $requestId,
            'error'      => $error,
        ];
    }

    /**
     * 处理位置查询 (MobilePosition Query)
     *
     * @param string $requestId 请求ID
     * @param string $deviceId 设备ID
     * @param string $deviceIp 设备IP
     * @param int $devicePort 设备端口
     * @param array $params 参数 ['interval' => 位置上报间隔(秒),可选]
     * @return array 处理结果
     */
    private function handleQueryMobilePosition(
        string $requestId,
        string $deviceId,
        string $deviceIp,
        int $devicePort,
        array $params
    ) : array
    {
        $this->log("Query mobile position: {$deviceId}");

        $interval = $params['interval'] ?? null; // 上报间隔(秒),可选
        $sn = rand(1, 99999999);

        // 构建 MobilePosition 查询 XML
        $xml = "<?xml version=\"1.0\" encoding=\"GB2312\"?>\r\n";
        $xml .= "<Query>\r\n";
        $xml .= "<CmdType>MobilePosition</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$deviceId}</DeviceID>\r\n";

        // 可选: 指定上报间隔
        if ($interval !== null) {
            $xml .= "<Interval>{$interval}</Interval>\r\n";
        }

        $xml .= "</Query>";

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";

        // 使用 QuerySender 发送 MESSAGE
        $result = $this->querySender->sendQuery($targetUri, $xml);

        if ($result) {
            $this->log("✓ Mobile position query sent");
            return [
                'success'    => true,
                'request_id' => $requestId,
                'device_id'  => $deviceId,
                'sn'         => $sn,
                'interval'   => $interval,
                'message'    => 'Mobile position query command sent',
            ];
        }

        return $this->errorResponse($requestId, 'Failed to send mobile position query');
    }


    public function handleDeviceUpdate(string $requestId, string $deviceId, array $params)
    {
        $str = json_encode($params, JSON_UNESCAPED_UNICODE);
        $this->log("[$requestId] Update device info: {$deviceId} - {$str}");

        $this->deviceManager->updateDeviceInfo($deviceId, $params);

        return [
            'success'    => true,
            'request_id' => $requestId,
            'message'    => 'Device info updated',
        ];
    }


    /**
     * 获取活跃会话
     */
    public function getActiveSessions() : array
    {
        return $this->activeSessions;
    }

    /**
     * 根据 call_id 查找活跃会话
     *
     * 用于 GB28181Handler::handleInviteResponse() 中，
     * 当收到 INVITE 的 200 OK 时，通过 call_id 找到对应的会话。
     * 这解决了两个问题：
     * 1. 避免依赖 Redis（session 只存在于 activeSessions 内存中）
     * 2. 避免 From/To URI 反转导致的 deviceId/channelId 错误
     *    （server-initiated INVITE 的 From 是服务器，To 是设备）
     *
     * @param int $callId eXosip call_id
     * @return array|null 会话信息（包含 device_id, channel_id, type, ssrc 等），不存在返回 null
     */
    public function findActiveSessionByCallId(int $callId) : ?array
    {
        foreach ($this->activeSessions as $key => $session) {
            if ($session['call_id'] === $callId) {
                $session['stream_key'] = $key;  // 附加 stream_key 以便后续操作
                return $session;
            }
        }
        return null;
    }

    /**
     * 清理超时会话
     */
    public function cleanupTimeoutSessions(int $timeout = 3600) : void
    {
        $now = time();
        $cleanedCount = 0;
        foreach ($this->activeSessions as $key => $session) {
            // 防御性编程：如果没有 started_at，跳过这个会话
            if (!isset($session['started_at'])) {
                $this->log("Session {$key} missing started_at, skipping cleanup", 'WARNING');
                continue;
            }

            if ($now - $session['started_at'] > $timeout) {
                $dialogId = $session['dialog_id'] ?? 0;
                $result = $this->sipServer->sendBye($session['call_id'], $dialogId);

                if ($result === false) {
                    $this->log("Cleanup session: sendBye failed for {$key} (call_id={$session['call_id']}, dialog_id={$dialogId})", 'WARNING');
                } else {
                    $this->log("Cleanup timeout session: {$key} (call_id={$session['call_id']})");
                }

                unset($this->activeSessions[$key]);
                $cleanedCount++;
            }
        }

        if ($cleanedCount > 0) {
            $this->log("Cleaned {$cleanedCount} timeout sessions", 'INFO');
        }
    }

    /**
     * 处理目录订阅
     *
     * 流程：
     * 1. Worker 发送 SUBSCRIBE 请求
     * 2. 立即返回 subscription_id
     * 3. 设备返回 200 OK 后，通过 onResponse 回调处理
     * 4. dialog_id 通过 postTask -> HTTP 回调推送到 API
     */
    private function handleSubscribeCatalog(string $requestId, string $deviceId, array $params) : array
    {
        $this->log("Subscribe catalog: {$deviceId}");

        $device = $this->deviceManager->getDeviceObject($deviceId);
        if (!$device) {
            return $this->errorResponse($requestId, "Device not found: {$deviceId}");
        }

        $expires = $params['expires'] ?? 3600; // 默认1小时

        try {
            // 发送 SUBSCRIBE 请求，获取 subscription_id
            $subscriptionId = $this->querySender->sendSubscribeCatalog($device, $expires);

            if ($subscriptionId === false) {
                throw new \RuntimeException("Failed to send SUBSCRIBE");
            }

            // 注册等待订阅响应的上下文（用于 onResponse 回调时匹配）
            $this->pendingSubscribes[$subscriptionId] = [
                'request_id'  => $requestId,
                'device_id'   => $deviceId,
                'event_type'  => 'Catalog',
                'expires'     => $expires,
                'params'      => ['expires' => $expires],
                'retry_count' => 0,
                'created_at'  => time(),
            ];

            // 立即返回，dialog_id 将通过异步回调推送
            return $this->successResponse($requestId, [
                'device_id'       => $deviceId,
                'event_type'      => 'Catalog',
                'subscription_id' => $subscriptionId,
                'expires'         => $expires,
                'message'         => 'SUBSCRIBE sent, dialog_id will be pushed via HTTP callback',
            ]);

        } catch (\Throwable $e) {
            return $this->errorResponse($requestId, "Subscribe catalog failed: {$e->getMessage()}");
        }
    }

    /**
     * 处理报警订阅
     */
    private function handleSubscribeAlarm(string $requestId, string $deviceId, array $params) : array
    {
        $this->log("Subscribe alarm: {$deviceId}");

        $device = $this->deviceManager->getDeviceObject($deviceId);
        if (!$device) {
            return $this->errorResponse($requestId, "Device not found: {$deviceId}");
        }

        $expires = $params['expires'] ?? 3600;
        $startAlarmPriority = $params['start_priority'] ?? null;
        $endAlarmPriority = $params['end_priority'] ?? null;
        $alarmMethod = $params['alarm_method'] ?? null;
        $alarmType = $params['alarm_type'] ?? null;
        $startAlarmTime = $params['start_alarm_time'] ?? null;
        $endAlarmTime = $params['end_alarm_time'] ?? null;

        try {
            $subscriptionId = $this->querySender->sendSubscribeAlarm(
                $device,
                $expires,
                $startAlarmPriority,
                $endAlarmPriority,
                $alarmMethod,
                $alarmType,
                $startAlarmTime,
                $endAlarmTime
            );

            if ($subscriptionId === false) {
                throw new \RuntimeException("Failed to send SUBSCRIBE");
            }

            // 注册等待响应上下文
            $this->pendingSubscribes[$subscriptionId] = [
                'request_id'  => $requestId,
                'device_id'   => $deviceId,
                'event_type'  => 'Alarm',
                'expires'     => $expires,
                'params'      => [
                    'expires'              => $expires,
                    'start_priority'       => $startAlarmPriority,
                    'end_priority'         => $endAlarmPriority,
                    'alarm_method'         => $alarmMethod,
                    'alarm_type'           => $alarmType,
                    'start_alarm_time'     => $startAlarmTime,
                    'end_alarm_time'       => $endAlarmTime,
                ],
                'retry_count' => 0,
                'created_at'  => time(),
            ];

            return $this->successResponse($requestId, [
                'device_id'       => $deviceId,
                'event_type'      => 'Alarm',
                'subscription_id' => $subscriptionId,
                'expires'         => $expires,
                'message'         => 'SUBSCRIBE sent, dialog_id will be pushed via HTTP callback',
            ]);

        } catch (\Throwable $e) {
            return $this->errorResponse($requestId, "Subscribe alarm failed: {$e->getMessage()}");
        }
    }

    /**
     * 处理移动位置订阅
     */
    private function handleSubscribeMobilePosition(string $requestId, string $deviceId, array $params) : array
    {
        $this->log("Subscribe mobile position: {$deviceId}");

        $device = $this->deviceManager->getDeviceObject($deviceId);
        if (!$device) {
            return $this->errorResponse($requestId, "Device not found: {$deviceId}");
        }

        $expires = $params['expires'] ?? 3600;
        $interval = $params['interval'] ?? 5;

        try {
            $subscriptionId = $this->querySender->sendSubscribeMobilePosition($device, $expires, $interval);

            if ($subscriptionId === false) {
                throw new \RuntimeException("Failed to send SUBSCRIBE");
            }

            // 注册等待响应上下文
            $this->pendingSubscribes[$subscriptionId] = [
                'request_id'  => $requestId,
                'device_id'   => $deviceId,
                'event_type'  => 'MobilePosition',
                'expires'     => $expires,
                'params'      => ['expires' => $expires, 'interval' => $interval],
                'retry_count' => 0,
                'created_at'  => time(),
            ];

            return $this->successResponse($requestId, [
                'device_id'       => $deviceId,
                'event_type'      => 'MobilePosition',
                'subscription_id' => $subscriptionId,
                'expires'         => $expires,
                'interval'        => $interval,
                'message'         => 'SUBSCRIBE sent, dialog_id will be pushed via HTTP callback',
            ]);

        } catch (\Throwable $e) {
            return $this->errorResponse($requestId, "Subscribe mobile position failed: {$e->getMessage()}");
        }
    }

    /**
     * 处理取消目录订阅
     */
    private function handleUnsubscribeCatalog(string $requestId, string $deviceId) : array
    {
        $this->log("Unsubscribe catalog: {$deviceId}");

        $device = $this->deviceManager->getDeviceObject($deviceId);
        if (!$device) {
            return $this->errorResponse($requestId, "Device not found: {$deviceId}");
        }

        try {
            $this->querySender->sendUnsubscribeCatalog($device);

            return $this->successResponse($requestId, [
                'device_id'  => $deviceId,
                'event_type' => 'Catalog',
                'action'     => 'unsubscribed',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($requestId, "Unsubscribe catalog failed: {$e->getMessage()}");
        }
    }

    /**
     * 处理取消报警订阅
     */
    private function handleUnsubscribeAlarm(string $requestId, string $deviceId) : array
    {
        $this->log("Unsubscribe alarm: {$deviceId}");

        $device = $this->deviceManager->getDeviceObject($deviceId);
        if (!$device) {
            return $this->errorResponse($requestId, "Device not found: {$deviceId}");
        }

        try {
            $this->querySender->sendUnsubscribeAlarm($device);

            return $this->successResponse($requestId, [
                'device_id'  => $deviceId,
                'event_type' => 'Alarm',
                'action'     => 'unsubscribed',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($requestId, "Unsubscribe alarm failed: {$e->getMessage()}");
        }
    }

    /**
     * 更新会话的 dialog_id (当收到 200 OK 响应时)
     *
     * 调用时机：在 onResponse 回调中收到 INVITE 的 200 OK 时
     *
     * @param int $callId Call ID
     * @param int $dialogId Dialog ID
     *
     * @example 在 gb28181_server.php 中：
     * ```php
     * $sipServer->onResponse = function($event) use ($commandDispatcher) {
     *     if ($event->getCode() === 200) {
     *         $commandDispatcher->updateSessionDialog($event->getCallId(), $event->getDialogId());
     *     }
     * };
     * ```
     */
    public function updateSessionDialog(int $callId, int $dialogId) : void
    {
        foreach ($this->activeSessions as $key => &$session) {
            if ($session['call_id'] === $callId) {
                $session['dialog_id'] = $dialogId;
                $this->log("Updated session {$key} with dialog_id: {$dialogId}");
                break;
            }
        }
    }

    /**
     * 处理取消移动位置订阅
     */
    private function handleUnsubscribeMobilePosition(string $requestId, string $deviceId) : array
    {
        $this->log("Unsubscribe mobile position: {$deviceId}");

        $device = $this->deviceManager->getDeviceObject($deviceId);
        if (!$device) {
            return $this->errorResponse($requestId, "Device not found: {$deviceId}");
        }

        try {
            $this->querySender->sendUnsubscribeMobilePosition($device);

            return $this->successResponse($requestId, [
                'device_id'  => $deviceId,
                'event_type' => 'MobilePosition',
                'action'     => 'unsubscribed',
            ]);
        } catch (\Throwable $e) {
            return $this->errorResponse($requestId, "Unsubscribe mobile position failed: {$e->getMessage()}");
        }
    }

    // ==================== 订阅响应处理 (非阻塞模式) ====================

    /**
     * 处理 SUBSCRIBE 200 OK 响应（在 onResponse 回调中调用）
     *
     * 流程：
     * 1. 从 pendingSubscribes 查找对应的请求上下文
     * 2. 通过 postTask() 异步推送到 GBServerHookController
     * 3. 清理 pendingSubscribes 条目
     *
     * @param int $subscriptionId SUBSCRIBE 请求返回的 subscription_id
     * @param int $dialogId 200 OK 响应中的 dialog_id
     * @param int $statusCode 响应状态码（200=成功）
     * @return void
     */
    public function handleSubscriptionResponse(int $subscriptionId, int $dialogId, int $statusCode = 200) : void
    {
        $this->log("handleSubscriptionResponse: subscription_id={$subscriptionId}, dialog_id={$dialogId}, status={$statusCode}");

        // 查找等待中的订阅请求
        if (!isset($this->pendingSubscribes[$subscriptionId])) {
            $this->log("No pending subscribe found for subscription_id: {$subscriptionId}");
            return;
        }

        $context = $this->pendingSubscribes[$subscriptionId];
        unset($this->pendingSubscribes[$subscriptionId]);

        $this->log("Found pending subscribe context: " . json_encode($context));

        // 构造回调数据
        $callbackPayload = [
            'scene'           => 'subscribe_response',
            'request_id'      => $context['request_id'],
            'device_id'       => $context['device_id'],
            'event_type'      => $context['event_type'],
            'subscription_id' => $subscriptionId,
            'dialog_id'       => $dialogId,
            'expires'         => $context['expires'] ?? 3600,
            'status_code'     => $statusCode,
            'success'         => ($statusCode >= 200 && $statusCode < 300) && $dialogId > 0,
            'timestamp'       => time(),
        ];

        // 异步推送到 Task 进程处理 HTTP 回调
        $this->postTask('subscribe_response', $callbackPayload);

        $this->log("✓ Posted subscribe_response task for dialog_id: {$dialogId}");
    }

    /**
     * 处理订阅失败响应（4xx/5xx/6xx）
     *
     * @param int $subscriptionId SUBSCRIBE 请求返回的 subscription_id
     * @param int $statusCode 错误响应状态码
     * @param string $reason 错误原因
     * @return void
     */
    public function handleSubscriptionError(int $subscriptionId, int $statusCode, string $reason = '') : void
    {
        $this->log("handleSubscriptionError: subscription_id={$subscriptionId}, status={$statusCode}, reason={$reason}");

        if (!isset($this->pendingSubscribes[$subscriptionId])) {
            $this->log("No pending subscribe found for subscription_id: {$subscriptionId}");
            return;
        }

        $context = $this->pendingSubscribes[$subscriptionId];
        unset($this->pendingSubscribes[$subscriptionId]);

        // 构造错误回调数据
        $callbackPayload = [
            'scene'           => 'subscribe_response',
            'request_id'      => $context['request_id'],
            'device_id'       => $context['device_id'],
            'event_type'      => $context['event_type'],
            'subscription_id' => $subscriptionId,
            'dialog_id'       => 0,
            'status_code'     => $statusCode,
            'success'         => false,
            'error'           => $reason ? : "Subscribe failed with status {$statusCode}",
            'timestamp'       => time(),
        ];

        $this->postTask('subscribe_response', $callbackPayload);

        $this->log("✓ Posted subscribe_error task for subscription_id: {$subscriptionId}");
    }

    /**
     * 异步推送任务到 Task 进程
     *
     * 用于非阻塞地发送 HTTP 回调到 GBServerHookController
     * 避免 curlPost 阻塞 Worker 进程
     *
     * @param string $type 任务类型（如 'subscribe_response'）
     * @param array $payload 任务数据
     * @return void
     */
    private function postTask(string $type, array $payload) : void
    {
        $taskData = [
            'action'       => 'api_callback',
            'type'         => $type,
            'payload'      => $payload,
            'api_hook_url' => $this->config['api_hook_url'] ?? '',
            'created_at'   => time(),
        ];

        $taskId = $this->sipServer->addTask($taskData);

        if ($taskId !== false && $taskId > 0) {
            $this->log("Task posted: #{$taskId} type={$type}");
        } else {
            // 如果 addTask 失败（如单进程模式），降级为同步调用
            $this->log("Warning: addTask failed, falling back to sync curlPost");
            $this->syncCurlPost($type, $payload);
        }
    }

    /**
     * 同步 HTTP POST（降级方案）
     *
     * 当 addTask 不可用时（单进程模式或无 Task Worker），直接同步调用
     *
     * @param string $type 回调类型
     * @param array $payload 回调数据
     * @return void
     */
    private function syncCurlPost(string $type, array $payload) : void
    {
        $apiUrl = $this->config['api_hook_url'] ?? '';
        if (empty($apiUrl)) {
            $this->log("Warning: api_hook_url not configured, skip sync curlPost");
            return;
        }

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $apiUrl,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-Gateway-Callback: ' . $type,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5, // 5秒超时，避免长时间阻塞
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                $this->log("Sync curlPost success: HTTP {$httpCode}");
            } else {
                $this->log("Sync curlPost failed: HTTP {$httpCode}, error: {$error}");
            }
        } catch (\Throwable $e) {
            $this->log("Sync curlPost exception: " . $e->getMessage());
        }
    }

    /**
     * 处理订阅续订（REFRESH SUBSCRIBE）
     *
     * @param string $requestId 请求ID
     * @param int $dialogId dialog_id (已存在的订阅会话)
     * @param array $params 参数 ['expires' => 新的过期时间, 'device_id' => 设备ID, 'event_type' => 事件类型]
     * @return array 处理结果
     */
    private function handleRefreshSubscribe(string $requestId, int $dialogId, array $params) : array
    {
        $this->log("Refresh subscribe (dialog_id: {$dialogId})");

        $expires = $params['expires'] ?? 3600;
        $deviceId = $params['device_id'] ?? '';
        $eventType = $params['event_type'] ?? 'Catalog';

        try {
            // 调用 ExoSip 的 refreshSubscribe() 方法
            $result = $this->sipServer->refreshSubscribe($dialogId, $expires);

            if ($result === false) {
                throw new \RuntimeException("Failed to send REFRESH SUBSCRIBE");
            }

            // 构造回调 payload
            $callbackPayload = [
                'scene'      => 'subscribe_refresh',
                'request_id' => $requestId,
                'device_id'  => $deviceId,
                'event_type' => $eventType,
                'dialog_id'  => $dialogId,
                'expires'    => $expires,
                'success'    => true,
                'action'     => 'refreshed',
                'timestamp'  => time(),
            ];

            // 通过 Task 进程异步回调到 API 层
            $this->postTask('subscribe_refresh', $callbackPayload);

            return [
                'success'    => true,
                'dialog_id'  => $dialogId,
                'request_id' => $requestId,
                'expires'    => $expires,
                'action'     => 'refreshed',
            ];
        } catch (\Throwable $e) {
            // 构造错误回调 payload
            $errorPayload = [
                'scene'      => 'subscribe_refresh',
                'request_id' => $requestId,
                'device_id'  => $deviceId,
                'event_type' => $eventType,
                'dialog_id'  => $dialogId,
                'expires'    => $expires,
                'success'    => false,
                'error'      => $e->getMessage(),
                'timestamp'  => time(),
            ];

            // 通过 Task 进程异步回调到 API 层
            $this->postTask('subscribe_refresh_error', $errorPayload);

            return [
                'success'    => false,
                'request_id' => $requestId,
                'error'      => "Refresh subscribe failed: {$e->getMessage()}",
            ];
        }
    }

    /**
     * 日志输出
     */
    private function log(string $message, string $level = 'INFO') : void
    {
        $time = date('Y-m-d H:i:s');
        $this->logger->log("[{$time}]  [CommandDispatcher] {$message}\n", $level);
    }
}