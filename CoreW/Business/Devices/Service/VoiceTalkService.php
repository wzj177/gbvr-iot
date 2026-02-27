<?php

namespace CoreW\Business\Devices\Service;

interface VoiceTalkService
{
    /**
     * 准备语音对讲（前端获取推流地址时调用）
     *
     * 流程：
     * 1. 验证设备和通道状态
     * 2. 检查是否已有活跃对讲会话（防重复）
     * 3. 创建对讲会话并分配 SSRC 和端口
     * 4. 返回前端推流地址（WebRTC/RTMP）
     * 5. 等待流到达后触发 handleStreamArrival
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $mode 模式: talk(对讲) 或 broadcast(广播)
     * @return array 返回结构:
     * [
     *     'session_id' => string,           // 会话ID
     *     'stream_id' => string,            // 流ID
     *     'mode' => string,                 // talk/broadcast
     *     'status' => string,               // waiting_stream
     *     'streams' => array,               // 推流地址集合
     *     'ssrc' => string,                 // SSRC
     *     'rtp_port' => int,                // RTP端口
     *     'expires_at' => string,           // 过期时间
     * ]
     * @throws \CoreW\Business\Common\CommonBizException 当设备不存在、未在线或通道不存在时
     */
    public function prepareTalk(string $deviceId, string $channelId, string $mode = 'talk'): array;

    /**
     * 停止语音对讲（通过 session_id）
     *
     * @param string $sessionId 会话ID
     * @return array
     */
    public function stopVoiceTalk(string $sessionId): array;

    /**
     * 停止语音对讲（通过内部调用）
     * 这是统一的资源清理入口，必须幂等
     *
     * @param array $session 会话数据
     * @param string $reason 结束原因: manual/timeout/stream_departure/rtp_error/sip_error
     * @return array
     */
    public function stopVoiceTalkBySession(array $session, string $reason = 'manual'): array;

    /**
     * 处理流到达事件（ZLM hook on_publish）
     *
     * @param string $app 应用名 (talk/broadcast)
     * @param string $stream 流ID
     * @param string $mediaServerId 媒体服务器ID
     * @return void
     */
    public function handleStreamArrival(string $app, string $stream, string $mediaServerId): void;

    /**
     * 处理流离开事件（ZLM hook on_unpublish）
     *
     * @param string $app 应用名
     * @param string $stream 流ID
     * @param string $mediaServerId 媒体服务器ID
     * @return void
     */
    public function handleStreamDeparture(string $app, string $stream, string $mediaServerId): void;

    /**
     * SIP 200 OK 回调处理
     *
     * @param string $sessionId 会话ID
     * @param array $sipResponse SIP响应数据
     * @return void
     */
    public function onSipResponseOk(string $sessionId, array $sipResponse): void;

    /**
     * 处理超时会话（定时任务调用）
     * 只更新状态为 FAILED，不做资源清理
     *
     * @return int 处理的会话数量
     */
    public function processTimeouts(): int;

    /**
     * 根据会话ID获取会话信息
     *
     * @param string $sessionId
     * @return array|null
     */
    public function getSession(string $sessionId): ?array;

    /**
     * 根据 call_id 获取会话信息
     *
     * @param string $callId SIP Call-ID
     * @return array|null
     */
    public function getSessionByCallId(string $callId): ?array;

    public function deleteStreamByStreamAndMediaServerId(string $stream, string $mediaServerId);

    /**
     * 为 Broadcast 设置 RTP（设备 INVITE 到达后调用）
     *
     * WVP 对齐时序：收到设备 INVITE 后根据设备 SDP 协商传输协议，
     * 调用 ZLM startSendRtpPassive 开端口。
     *
     * @param string $sessionId 会话ID
     * @param array $deviceSdpInfo 设备 SDP 信息
     * @return array 包含 local_port, media_server_ip, ssrc, tcp_mode
     */
    public function setupBroadcastRtp(string $sessionId, array $deviceSdpInfo): array;

    /**
     * 检查流是否在 ZLMediaKit 中存在（就绪状态）
     *
     * @param string $mediaServerId 媒体服务器ID
     * @param string $app 应用名 (talk/broadcast)
     * @param string $stream 流ID
     * @return bool 流存在返回 true，不存在返回 false
     */
    public function isStreamReady(string $mediaServerId, string $app, string $stream): bool;
}