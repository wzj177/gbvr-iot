<?php

namespace Gb28181\GateWay\Message;

/**
 * GB28181 SDP 构造器
 *
 * 负责构造符合 GB28181 标准的 SDP 消息体
 */
class SdpBuilder
{
    /**
     * 构建 INVITE SDP
     *
     * @param array $params 参数:
     *   - server_id: 服务器ID (20位)
     *   - media_ip: 媒体服务器IP
     *   - media_port: 媒体服务器端口
     *   - session_name: 会话名称 (Play/Playback/Talk)
     *   - mode: 媒体模式 (sendonly/recvonly/sendrecv)
     *   - ssrc: 平台SSRC
     *   - tcp_mode: TCP模式 (0=UDP, 1=TCP被动, 2=TCP主动)
     *   - payload_types: payload类型配置 (可选)
     *   - start_time: 开始时间 (录像回放用, 可选)
     *   - end_time: 结束时间 (录像回放用, 可选)
     * @return string SDP字符串
     */
    public static function buildInviteSdp(array $params): string
    {
        // 必需参数验证
        $required = ['server_id', 'media_ip', 'media_port', 'session_name', 'mode', 'ssrc'];
        foreach ($required as $key) {
            if (!isset($params[$key])) {
                throw new \InvalidArgumentException("Missing required parameter: {$key}");
            }
        }

        $serverId = $params['server_id'];
        $ip = $params['media_ip'];
        $port = $params['media_port'];
        $sessionName = $params['session_name'];
        $mode = $params['mode'];
        $ssrc = $params['ssrc'];
        $tcpMode = $params['tcp_mode'] ?? 0;

        // 根据 TCP 模式选择传输协议
        $transport = self::getTransportProtocol($tcpMode);

        // 时间参数 (0 0 表示永久会话，录像回放时需要指定)
        $startTime = $params['start_time'] ?? 0;
        $endTime = $params['end_time'] ?? 0;

        // Payload 类型配置 (默认 GB28181 标准配置)
        $payloads = $params['payload_types'] ?? [
            96 => ['type' => 'PS', 'rate' => 90000],      // PS 流(最常用)
            98 => ['type' => 'H264', 'rate' => 90000],    // H264
            97 => ['type' => 'MPEG4', 'rate' => 90000],   // MPEG4
        ];

        // 构造 SDP
        $sdp = "v=0\r\n";
        $sdp .= "o={$serverId} 0 0 IN IP4 {$ip}\r\n";
        $sdp .= "s={$sessionName}\r\n";
        $sdp .= "c=IN IP4 {$ip}\r\n";
        $sdp .= "t={$startTime} {$endTime}\r\n";

        // m= 行: 媒体描述
        $payloadList = implode(' ', array_keys($payloads));
        $sdp .= "m=video {$port} {$transport} {$payloadList}\r\n";

        // a= 行: 媒体属性
        $sdp .= "a={$mode}\r\n";

        // TCP 模式的额外属性
        if ($tcpMode == 1) {
            $sdp .= "a=setup:passive\r\n";    // 被动模式：等待设备连接
            $sdp .= "a=connection:new\r\n";
        } elseif ($tcpMode == 2) {
            $sdp .= "a=setup:active\r\n";     // 主动模式：主动连接设备
            $sdp .= "a=connection:new\r\n";
        }

        // rtpmap 映射
        foreach ($payloads as $pt => $config) {
            $sdp .= "a=rtpmap:{$pt} {$config['type']}/{$config['rate']}\r\n";
        }

        // GB28181 扩展字段
        $sdp .= "y={$ssrc}\r\n";  // SSRC (流标识)
        $sdp .= "f=\r\n";         // f= 字段 (预留)

        return $sdp;
    }

    /**
     * 根据 TCP 模式获取传输协议
     */
    private static function getTransportProtocol(int $tcpMode): string
    {
        return match ($tcpMode) {
            1, 2 => 'TCP/RTP/AVP',  // TCP 被动或主动模式
            default => 'RTP/AVP',     // UDP 模式
        };
    }

    /**
     * 快捷方法: 构建实时视频 SDP
     */
    public static function buildLiveVideoSdp(
        string $serverId,
        string $mediaIp,
        int    $mediaPort,
        string $ssrc,
        int    $tcpMode = 0
    ): string
    {
        return self::buildInviteSdp([
            'server_id' => $serverId,
            'media_ip' => $mediaIp,
            'media_port' => $mediaPort,
            'session_name' => 'Play',
            'mode' => 'recvonly',
            'ssrc' => $ssrc,
            'tcp_mode' => $tcpMode,
        ]);
    }

    /**
     * 快捷方法: 构建录像回放 SDP
     */
    public static function buildPlaybackSdp(
        string $serverId,
        string $mediaIp,
        int    $mediaPort,
        string $ssrc,
        int    $startTime,
        int    $endTime,
        int    $tcpMode = 0
    ): string
    {
        return self::buildInviteSdp([
            'server_id' => $serverId,
            'media_ip' => $mediaIp,
            'media_port' => $mediaPort,
            'session_name' => 'Playback',
            'mode' => 'recvonly',
            'ssrc' => $ssrc,
            'tcp_mode' => $tcpMode,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
    }

    /**
     * 快捷方法: 构建语音对讲 SDP
     */
    public static function buildTalkSdp(
        string $serverId,
        string $mediaIp,
        int    $mediaPort,
        string $ssrc,
        int    $tcpMode = 0,
        string $mode = 'sendrecv'
    ): string
    {
        return self::buildInviteSdp([
            'server_id' => $serverId,
            'media_ip' => $mediaIp,
            'media_port' => $mediaPort,
            'session_name' => 'Talk',
            'mode' => $mode,
            'ssrc' => $ssrc,
            'tcp_mode' => $tcpMode,
            // 语音对讲通常只需要 PS 流
            'payload_types' => [
                96 => ['type' => 'PS', 'rate' => 90000],
            ],
        ]);
    }
}
