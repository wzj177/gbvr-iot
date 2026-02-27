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
     *   - session_name: 会话名称 (Play/Playback/Talk/Broadcast/Download)
     *   - mode: 媒体模式 (sendonly/recvonly/sendrecv)
     *   - ssrc: 平台SSRC
     *   - tcp_mode: TCP模式 (0=UDP, 1=TCP被动, 2=TCP主动)
     *   - senior_sdp: 是否使用扩展SDP (默认false, WVP兼容)
     *   - stream_identification: 流标识属性 (可选)
     *   - payload_types: payload类型配置 (可选, 覆盖默认)
     *   - start_time: 开始时间 (录像回放用, 可选)
     *   - end_time: 结束时间 (录像回放用, 可选)
     *   - download_speed: 下载速度 (Download模式用, 可选)
     *   - sdp_owner: SDP o= 行的 owner 标识 (可选, 默认使用 server_id; Talk 场景应传入 channel_id, 与 WVP 一致)
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
        $seniorSdp = $params['senior_sdp'] ?? false;
        $streamId = $params['stream_identification'] ?? null;
        // o= 行的 owner: Talk 场景使用 channel_id (与 WVP 一致), 其他场景使用 server_id
        $sdpOwner = $params['sdp_owner'] ?? $serverId;

        //  判断是否为音频会话 (Talk 或 Broadcast 都是纯音频)
        $isAudioOnly = ($sessionName === 'Talk' || $sessionName === 'Broadcast');

        // 根据 TCP 模式选择传输协议
        $transport = self::getTransportProtocol($tcpMode);

        // 时间参数 (0 0 表示永久会话，录像回放时需要指定)
        $startTime = $params['start_time'] ?? 0;
        $endTime = $params['end_time'] ?? 0;

        //  音频会话 (Talk/Broadcast) 的特殊处理：只使用音频 Payload
        if ($isAudioOnly) {
            // 固定使用 Payload Type 8 (PCMA/8000)
            $payloads = [
                8 => ['type' => 'PCMA', 'rate' => 8000, 'channels' => 1],
            ];
            $mediaType = 'audio';  // 使用 m=audio
        } else {
            // 视频会话的 Payload 配置
            if (isset($params['payload_types'])) {
                $payloads = $params['payload_types'];
            } elseif ($seniorSdp) {
                $payloads = [
                    96  => ['type' => 'PS', 'rate' => 90000],
                    126 => ['type' => 'H264', 'rate' => 90000, 'fmtp' => 'profile-level-id=42e01e'],
                    125 => ['type' => 'H264S', 'rate' => 90000, 'fmtp' => 'profile-level-id=42e01e'],
                    99  => ['type' => 'H265', 'rate' => 90000],
                    34  => ['type' => 'H263', 'rate' => 90000],
                    98  => ['type' => 'H264', 'rate' => 90000],
                    97  => ['type' => 'MPEG4', 'rate' => 90000],
                ];
            } else {
                $payloads = [
                    96 => ['type' => 'PS', 'rate' => 90000],
                    97 => ['type' => 'MPEG4', 'rate' => 90000],
                    98 => ['type' => 'H264', 'rate' => 90000],
                    99 => ['type' => 'H265', 'rate' => 90000],
                ];
            }
            $mediaType = 'video';  // 使用 m=video
        }

        // 构造 SDP
        $sdp = "v=0\r\n";
        $sdp .= "o={$sdpOwner} 0 0 IN IP4 {$ip}\r\n";
        $sdp .= "s={$sessionName}\r\n";
        $sdp .= "c=IN IP4 {$ip}\r\n";
        $sdp .= "t={$startTime} {$endTime}\r\n";

        // m= 行: 媒体描述
        $payloadList = implode(' ', array_keys($payloads));
        $sdp .= "m={$mediaType} {$port} {$transport} {$payloadList}\r\n";

        // a= 行: 媒体属性 (顺序与 WVP 一致: setup → connection → direction → rtpmap)

        // TCP 模式的额外属性 (放在 direction 之前)
        if ($tcpMode == 1) {
            $sdp .= "a=setup:passive\r\n";
            $sdp .= "a=connection:new\r\n";
        } elseif ($tcpMode == 2) {
            $sdp .= "a=setup:active\r\n";
            $sdp .= "a=connection:new\r\n";
        }

        $sdp .= "a={$mode}\r\n";

        // rtpmap 映射
        foreach ($payloads as $pt => $config) {
            if (isset($config['fmtp'])) {
                $sdp .= "a=fmtp:{$pt} {$config['fmtp']}\r\n";
            }
            //  音频会话使用 PCMA/8000 (单声道)
            if ($isAudioOnly) {
                $sdp .= "a=rtpmap:{$pt} {$config['type']}/{$config['rate']}\r\n";
            } else {
                $sdp .= "a=rtpmap:{$pt} {$config['type']}/{$config['rate']}\r\n";
            }
        }

        // 流标识属性
        if ($streamId !== null && $streamId !== '') {
            $sdp .= "a={$streamId}\r\n";
        }

        // GB28181 扩展字段
        $sdp .= "y={$ssrc}\r\n";

        // f= 字段：媒体格式描述
        if ($isAudioOnly) {
            //  Talk/Broadcast 的 f= 格式：v/////a/1/8/1
            // 含义：视频(无)/音频(通道1/Payload8/通道数1)
            $sdp .= "f=v/////a/1/8/1\r\n";
        } elseif ($sessionName === 'Download' && isset($params['download_speed'])) {
            $speed = $params['download_speed'];
            $sdp .= "f=v/{$speed}///a///\r\n";
        } else {
            $sdp .= "f=\r\n";
        }

        return $sdp;
    }

    /**
     * 根据 TCP 模式获取传输协议
     */
    private static function getTransportProtocol(int $tcpMode): string
    {
        return match ($tcpMode) {
            1, 2 => 'TCP/RTP/AVP',
            default => 'RTP/AVP',
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
        int    $tcpMode = 0,
        bool   $seniorSdp = false,
        ?string $streamId = null,
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
            'senior_sdp' => $seniorSdp,
            'stream_identification' => $streamId,
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
        int    $tcpMode = 0,
        bool   $seniorSdp = false,
        ?string $streamId = null
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
            'senior_sdp' => $seniorSdp,
            'stream_identification' => $streamId,
        ]);
    }

    /**
     * 快捷方法: 构建语音对讲 SDP
     *
     * @param string $serverId 服务器ID (20位国标编码)
     * @param string $mediaIp 媒体服务器IP
     * @param int $mediaPort 媒体服务器端口
     * @param string $ssrc SSRC标识
     * @param int $tcpMode TCP模式 (0=UDP, 1=TCP被动, 2=TCP主动)
     * @param string $mode 媒体模式:
     *                     - 'recvonly': 设备只接收音频(平台→设备)
     *                     - 'sendonly': 设备只发送音频(设备→平台)
     *                     - 'sendrecv': 双向对讲
     * @param string|null $channelId 通道ID，用于 SDP o= 行 (与 WVP 一致，Talk 使用 channelId 而非 serverId)
     * @return string 符合 GB28181 标准的 Talk SDP
     */
    public static function buildTalkSdp(
        string $serverId,
        string $mediaIp,
        int    $mediaPort,
        string $ssrc,
        int    $tcpMode = 0,
        string $mode = 'recvonly',  //  默认 recvonly (设备接收音频)
        ?string $channelId = null
    ): string
    {
        return self::buildInviteSdp([
            'server_id' => $serverId,
            'media_ip' => $mediaIp,
            'media_port' => $mediaPort,
            'session_name' => 'Talk',       // 关键标识
            'mode' => $mode,                // recvonly/sendonly/sendrecv
            'ssrc' => $ssrc,
            'tcp_mode' => $tcpMode,
            'sdp_owner' => $channelId ?? $serverId,  // Talk 使用 channelId (与 WVP 一致)
        ]);
    }

    /**
     * 快捷方法: 构建语音广播 SDP (用于回复设备 INVITE 的 200 OK)
     *
     * 与 Talk SDP 的区别:
     * - session_name = 'Broadcast' (不是 'Talk')
     * - sdp_owner = serverId (不是 channelId，与 WVP 一致)
     * - 默认 mode = 'sendonly' (服务器发送音频到设备)
     *
     * @param string $serverId 服务器ID (20位国标编码)
     * @param string $mediaIp 媒体服务器IP
     * @param int $mediaPort 媒体服务器端口 (ZLM startSendRtpPassive 返回的端口)
     * @param string $ssrc SSRC标识
     * @param int $tcpMode TCP模式 (0=UDP, 1=TCP被动, 2=TCP主动)
     * @param string $mode 媒体模式 (默认 sendonly - 服务器发送音频到设备)
     * @return string 符合 GB28181 标准的 Broadcast SDP
     */
    public static function buildBroadcastSdp(
        string $serverId,
        string $mediaIp,
        int    $mediaPort,
        string $ssrc,
        int    $tcpMode = 0,
        string $mode = 'sendonly',
    ): string
    {
        return self::buildInviteSdp([
            'server_id' => $serverId,
            'media_ip' => $mediaIp,
            'media_port' => $mediaPort,
            'session_name' => 'Broadcast',
            'mode' => $mode,
            'ssrc' => $ssrc,
            'tcp_mode' => $tcpMode,
            // sdp_owner 默认使用 server_id（广播模式用 serverId，与 WVP 一致）
        ]);
    }

    /**
     * 快捷方法: 构建录像下载 SDP
     */
    public static function buildDownloadSdp(
        string $serverId,
        string $mediaIp,
        int    $mediaPort,
        string $ssrc,
        int    $startTime,
        int    $endTime,
        int    $tcpMode = 0,
        int    $downloadSpeed = 1
    ): string
    {
        return self::buildInviteSdp([
            'server_id' => $serverId,
            'media_ip' => $mediaIp,
            'media_port' => $mediaPort,
            'session_name' => 'Download',
            'mode' => 'recvonly',
            'ssrc' => $ssrc,
            'tcp_mode' => $tcpMode,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'payload_types' => [
                96 => ['type' => 'PS', 'rate' => 90000],
            ],
            'download_speed' => $downloadSpeed,
        ]);
    }
}