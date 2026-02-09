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

    public function deleteStreamByStreamAndMediaServerId(string $stream, string $mediaServerId);
}