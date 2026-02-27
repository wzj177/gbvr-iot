<?php

namespace CoreW\Sdk\ZLMediaKit;

use CoreW\Sdk\ZLMediaKit\Dos\SendRtpDo;
use support\Log;

/**
 * ZLMediaKit HTTP API 客户端
 *
 * 封装ZLMediaKit的HTTP API接口
 * 文档: https://docs.zlmediakit.com/zh/guide/media_server/web_api.html
 */
class ZLMClient
{
    private string $host;
    private int $port;
    private int $httpsPort;
    private string $secret;
    private bool $debug;
    private string $baseUrl;


    /**
     * 构造函数
     *
     * @param array $config 配置参数
     *   - host: ZLM服务器地址 (默认: 127.0.0.1)
     *   - port: ZLM HTTP API端口 (默认: 80)
     *   - secret: API密钥
     *   - debug: 是否开启调试日志 (默认: false)
     */
    public function __construct(array $config = [])
    {
        $this->host = $config['host'] ?? '127.0.0.1';
        $this->port = $config['port'] ?? 80;
        $this->httpsPort = $config['https_port'] ?? 4443;
        $this->secret = $config['secret'] ?? '';
        $this->debug = $config['debug'] ?? false;

        $this->baseUrl = "http://{$this->host}:{$this->port}/index/api";
    }

    /**
     * 列出RTP服务器
     * {
     * "code" : 0,
     * "data" : [
     * {
     * "port" : 52183, #绑定的端口号
     * "stream_id" : "test" #绑定的流ID
     * }
     * ]
     * }
     * @return array|null
     */
    public function listRtpServer(): ?array
    {
        $result = $this->request('listRtpServer', []);

        if ($result && $result['code'] === 0) {
            if ($this->debug) {
                Log::channel('zlm')->info('listRtpServer success', $result);
            }

            return $result['data'] ?? [];
        }

        return [];
    }

    /**
     * 开启RTP服务器端口
     * 用于接收设备推送的RTP流
     *
     * @param string $streamId 流ID (推荐格式: {device_id}_{channel_id})
     * @param int $port 指定端口 (0表示自动分配)
     * @param int $tcpMode TCP模式
     * @param string|null $ssrc 平台SSRC (可选)
     * @return array|null ['code' => 0, 'port' => 端口号]
     */
    public function openRtpServer(string $streamId, int $port = 0, int $tcpMode = 1, ?string $ssrc = null): ?array
    {
        $params = [
            'vhost' => '__defaultVhost__',
            'app' => 'rtp',
            'stream_id' => $streamId,
            'tcp_mode' => $tcpMode, //tcp模式，0时为不启用tcp监听，1时为启用tcp监听，2时为tcp主动连接模式
            'port' => $port,
        ];


        if ($ssrc) {
            $params['ssrc'] = $ssrc; // 是否指定收流的rtp ssrc, 十进制数字，不指定或指定0时则不过滤rtp，非必选参数
        }

        $result = $this->request('openRtpServer', $params);

        if ($result && $result['code'] === 0) {
            if ($this->debug) {
                Log::channel('zlm')->info('RTP Server opened', [
                    'stream_id' => $streamId,
                    'port' => $result['port'],
                    'tcp_mode' => $tcpMode,
                ]);
            }
        }

        return $result;
    }

    /**
     * 关闭RTP服务器端口
     *
     * @param string $streamId 流ID
     * @return array|null
     */
    public function closeRtpServer(string $streamId): ?array
    {
        $result = $this->request('closeRtpServer', [
            'vhost' => '__defaultVhost__',
            'app' => 'rtp',
            'stream_id' => $streamId,
        ]);

        if ($this->debug && $result) {
            Log::channel('zlm')->info('RTP Server closed', [
                'stream_id' => $streamId,
                'code' => $result['code'],
            ]);
        }

        return $result;
    }

    public function getRtpInfo(string $streamId)
    {
        $result = $this->request('getRtpInfo', [
            'vhost' => '__defaultVhost__',
            'app' => 'rtp',
            'stream_id' => $streamId,
        ]);
        if ($this->debug && $result) {
            Log::channel('zlm')->info('RTP Server closed', [
                'stream_id' => $streamId,
                'code' => $result['code'],
            ]);
        }
        return $result;
    }

    /**
     * 更新RTP服务器的SSRC
     * 在收到设备200 OK后调用，告知ZLM设备的SSRC
     *
     * @param string $streamId 流ID
     * @param string $ssrc 设备SSRC
     * @return array|null
     */
    public function updateRtpServerSsrc(string $streamId, string $ssrc): ?array
    {
        $result = $this->request('updateRtpServerSSRC', [
            'vhost' => '__defaultVhost__',
            'app' => 'rtp',
            'stream_id' => $streamId,
            'ssrc' => $ssrc,
        ]);

        if ($this->debug && $result) {
            Log::channel('zlm')->info('RTP Server SSRC updated', [
                'stream_id' => $streamId,
                'ssrc' => $ssrc,
            ]);
        }

        return $result;
    }

    /**
     * 获取流列表
     *
     * @param string|null $app 应用名 (null=所有)
     * @param string|null $stream 流ID (null=所有)
     * @param string|null $schema 播放协议 (null=所有)
     * @return array
     */
    public function getMediaList(?string $app = null, ?string $stream = null, ?string $schema = null): array
    {
        $params = [
            'vhost' => '__defaultVhost__',
        ];

        if ($app) {
            $params['app'] = $app;
        }
        if ($stream) {
            $params['stream'] = $stream;
        }

        if ($schema) {
            $params['schema'] = $schema;
        }
        $resp =  $this->request('getMediaList', $params);

        if ($resp['code'] === 0) {
            return $resp['data'] ?? [];
        }

        return [];
    }

    /**
     * 获取流播放人数列表
     *
     * @param string|null $app 应用名 (null=所有)
     * @param string|null $stream 流ID (null=所有)
     * @return array
     */
    public function getMediaPlayerList(string $schema, string $stream, string $app = 'rtp'): array
    {
        $params = [
            'vhost' => '__defaultVhost__',
        ];

        if ($app) {
            $params['app'] = $app;
        }
        if ($stream) {
            $params['stream'] = $stream;
        }

        $resp =  $this->request('getMediaPlayerList', $params);

        if ($resp['code'] === 0) {
            return $resp['data'];
        }

        return [];
    }

    /**
     * 关闭流
     *
     * @param string $app 应用名 (如: rtp)
     * @param string $stream 流ID
     * @param bool $force 是否强制关闭
     * @return array|null
     */
    public function closeStream(string $app, string $stream, bool $force = false): ?array
    {
        $result = $this->request('close_stream', [
            'vhost' => '__defaultVhost__',
            'app' => $app,
            'stream' => $stream,
            'force' => $force ? 1 : 0,
        ]);

        if ($this->debug && $result) {
            Log::channel('zlm')->info('Stream closed', [
                'app' => $app,
                'stream' => $stream,
            ]);
        }

        return $result;
    }

    /**
     * 获取推流地址（用于语音对讲等场景）
     *
     * @param string $streamId 流ID
     * @param string $app 应用名 (默认: talk)
     * @param string|null $accessDomain 访问地址（nginx反向代理地址，如果提供则使用此地址生成推流URL）
     * @return array
     */
    public function getPushUrls(string $streamId, string $app = 'talk', ?string $accessDomain = null): array
    {
        $domain = !empty($accessDomain) ? $accessDomain : $this->host;

        $httpsPort = $this->httpsPort ?? 443;  // HTTPS 端口
        $rtmpPort = 1935;                 // RTMP 端口
        $rtspPort = 554;                  // RTSP 端口
        $srtPort = 9000;                  // SRT 端口

        return [
            // WebRTC HTTPS 推流地址（浏览器 HTTPS 页面必须用这个）
            'webrtcs' => "https://{$domain}:{$httpsPort}/index/api/webrtc?app={$app}&stream={$streamId}&type=push",
            // RTMP 推流地址（常用）
            'rtmp' => "rtmp://{$domain}:{$rtmpPort}/{$app}/{$streamId}",
            // RTSP 推流地址
            'rtsp' => "rtsp://{$domain}:{$rtspPort}/{$app}/{$streamId}",
            // SRT 推流地址（低延迟）
            'srt' => "srt://{$domain}:{$srtPort}?streamid=#!::r={$app}/{$streamId},m=publish",
        ];
    }

    /**
     * 获取播放地址
     *
     * @param string $streamId 流ID
     * @param string $app 应用名 (默认: rtp)
     * @param string|null $accessUrl 访问地址（nginx反向代理地址，如果提供则使用此地址生成播放URL）
     * @return array ['rtsp' => '', 'http_flv' => '', 'hls' => '', 'ws_flv' => '']
     */
    public function getPlayUrls(string $streamId, string $app = 'rtp', ?string $accessDomain = null): array
    {
        // TODO: 读取 zlm config
        $vhost = '__defaultVhost__';
        $domain = !empty($accessDomain) ? $accessDomain : $this->host;

        return [
//            'rtsp' => "rtsp://{$this->host}:{$rtspPort}/{$app}/{$streamId}?vhost={$vhost}",
            'http_flv' => "http://{$domain}:{$this->port}/{$app}/{$streamId}.live.flv?vhost={$vhost}",
            'https_flv' => "https://{$domain}:{$this->httpsPort}/{$app}/{$streamId}.live.flv?vhost={$vhost}",
            'ws_flv' => "ws://{$domain}:{$this->port}/{$app}/{$streamId}.live.flv?vhost={$vhost}",
            'wss_flv' => "wss://{$domain}:{$this->httpsPort}/{$app}/{$streamId}.live.flv?vhost={$vhost}",
            'hls' => "http://{$domain}:{$this->port}/{$app}/{$streamId}/hls.m3u8?vhost={$vhost}",
            "https_hls" => "https://{$domain}:{$this->httpsPort}/{$app}/{$streamId}/hls.m3u8?vhost={$vhost}",
            'hls_fmp4' => "http://{$domain}:{$this->port}/{$app}/{$streamId}/hls.fmp4.m3u8?vhost={$vhost}",
            "https_hls_fmp4" => "https://{$domain}:{$this->httpsPort}/{$app}/{$streamId}/hls_fmp4.m3u8?vhost={$vhost}",
        ];
    }

    /**
     * 获取服务器配置
     *
     * @return array|null
     */
    public function getServerConfig(bool $flat = false): ?array
    {
        $result =  $this->request('getServerConfig');
        if ($result && ($result['code'] ?? -1) === 0) {
            if (!$flat) {
                return $result['data'][0];
            } else {
                $items = [];
                foreach ($result['data'][0] as $key => $value) {
                    $keys = explode('.', $key);
                    $items[$keys[0]][$keys[1]] = $value;
                }
                return $items;
            }
        }

        return null;
    }

    public function setServerConfig(array $params): ?array
    {
        $config = [];
        foreach ($params as $key => $value) {
            foreach ($value as $k => $v) {
                $config["{$key}.{$k}"] = $v;
            }
        }

        return $this->request('setServerConfig', $config);
    }

    /**
     * 重启服务器
     *
     * @return array|null
     */
    public function restartServer(): ?array
    {
        return $this->request('restartServer');
    }

    /**
     * 获取网络线程负载
     *
     * @return array{
     *     code: int,
     *     data: list<array{
     *         delay: int,
     *         fd_count: int,
     *         load: int,
     *         name: string
     *     }>
     * } |  null |array
     */
    public function getThreadsLoad(): ?array
    {
        return $this->request('getThreadsLoad');
    }

    /**
     * 获取后台线程负载
     *
     * @return array{
     *     code: int,
     *     data: list<array{
     *         delay: int,
     *         fd_count: int,
     *         load: int,
     *         name: string
     *     }>
     * } |  null |array
     */
    public function getWorkThreadsLoad(): ?array
    {
        return $this->request('getWorkThreadsLoad');
    }

    /**
     * 获取对象统计信息
     * 用于分析内存性能
     *
     * @return array{
     *     code: int,
     *     data: array<string, int>
     * } | null
     */
    public function getStatistic(): ?array
    {
        return $this->request('getStatistic');
    }

    /**
     * 获取RTP端口范围
     *
     * @return array ['start' => 开始端口, 'end' => 结束端口]
     */
    public function getRtpPortRange(): array
    {
        $config = $this->getServerConfig();

        if ($config && isset($config['data'])) {
            $rtpProxy = $config['data'][0]['rtp_proxy.port_range'] ?? '30000,40000';
            [$start, $end] = explode(',', $rtpProxy);

            return [
                'start' => (int)$start,
                'end' => (int)$end,
            ];
        }

        return [
            'start' => 30000,
            'end' => 40000,
        ];
    }

    /**
     * 开始录制hls或MP4
     *
     * @param string $vhost 虚拟主机 (如: __defaultVhost__)
     * @param string $app 应用名 (如: rtp)
     * @param string $stream 流id
     * @param int $type 0为hls，1为mp4
     * @param string $customizedPath 录像保存目录 (可选)
     * @param int $maxSecond mp4录像切片时间大小,单位秒 (可选)
     * @return bool 成功与否
     */
    public function startRecord(string $vhost, string $app, string $stream, int $type = 1, string $customizedPath = '', int $maxSecond = 0): bool
    {
        $params = [
            'vhost' => $vhost,
            'app' => $app,
            'stream' => $stream,
            'type' => $type,
        ];

        if ($customizedPath) {
            $params['customized_path'] = $customizedPath;
        }

        if ($maxSecond > 0) {
            $params['max_second'] = $maxSecond;
        }

        $result = $this->request('startRecord', $params);

        if ($this->debug) {
            Log::channel('zlm')->info('Start record', [
                'vhost' => $vhost,
                'app' => $app,
                'stream' => $stream,
                'type' => $type,
                'result' => $result,
            ]);
        }

        return $result && ($result['code'] ?? -1) === 0 && ($result['result'] ?? false);
    }

    /**
     * 停止录制流
     *
     * @param string $vhost 虚拟主机 (如: __defaultVhost__)
     * @param string $app 应用名 (如: rtp)
     * @param string $stream 流id
     * @param int $type 0为hls，1为mp4
     * @return bool 成功与否
     */
    public function stopRecord(string $vhost, string $app, string $stream, int $type = 1): bool
    {
        $params = [
            'vhost' => $vhost,
            'app' => $app,
            'stream' => $stream,
            'type' => $type,
        ];

        $result = $this->request('stopRecord', $params);

        if ($this->debug && $result) {
            Log::channel('zlm')->info('Stop record', [
                'vhost' => $vhost,
                'app' => $app,
                'stream' => $stream,
                'type' => $type,
                'code' => $result['code'] ?? null,
            ]);
        }

        return $result && ($result['code'] ?? -1) === 0 && ($result['result'] ?? false);
    }

    /**
     * 获取流录制状态
     *
     * @param string $vhost 虚拟主机 (如: __defaultVhost__)
     * @param string $app 应用名 (如: rtp)
     * @param string $stream 流id
     * @param int $type 0为hls，1为mp4
     * @return bool|null false:未录制,true:正在录制
     */
    public function isRecording(string $vhost, string $app, string $stream, int $type = 1): ?bool
    {
        $params = [
            'vhost' => $vhost,
            'app' => $app,
            'stream' => $stream,
            'type' => $type,
        ];

        $result = $this->request('isRecording', $params);

        if ($this->debug && $result) {
            Log::channel('zlm')->info('Check recording status', [
                'vhost' => $vhost,
                'app' => $app,
                'stream' => $stream,
                'type' => $type,
                'status' => $result['status'] ?? null,
            ]);
        }

        return $result && ($result['code'] ?? -1) === 0 ? ($result['status'] ?? false) : null;
    }

    /**
     * 获取MP4录像文件列表
     *
     * @param string $vhost 虚拟主机 (如: __defaultVhost__)
     * @param string $app 应用名 (如: rtp)
     * @param string $stream 流id
     * @param string $period 流的录像日期，格式为2020-01-24
     * @param string $customizedPath 自定义搜索路径 (可选)
     * @return array|null ['paths' => [], 'rootPath' => '']
     */
    public function getMp4RecordFile(string $vhost, string $app, string $stream, string $period = '', string $customizedPath = ''): ?array
    {
        $params = [
            'vhost' => $vhost,
            'app' => $app,
            'stream' => $stream,
        ];

        if ($period) {
            $params['period'] = $period;
        }

        if ($customizedPath) {
            $params['customized_path'] = $customizedPath;
        }

        $result = $this->request('getMp4RecordFile', $params);

        if ($this->debug && $result) {
            Log::channel('zlm')->info('Get MP4 record files', [
                'vhost' => $vhost,
                'app' => $app,
                'stream' => $stream,
                'period' => $period,
                'code' => $result['code'] ?? null,
            ]);
        }

        return $result && ($result['code'] ?? -1) === 0 ? ($result['data'] ?? null) : null;
    }


    public function getVersion(): ?array
    {
        $resp = $this->request('version');

        if (empty($resp)) {
            return null;
        }

        return $resp['data'] ?? null;
    }

    /**
     * 启动发送RTP（主动推流到指定目标）
     * 参考 WVP-PRO 实现
     *
     * @param string $vhost 虚拟主机
     * @param string $app 应用名
     * @param string $stream 流ID
     * @param string $ssrc RTP SSRC
     * @param string $dst_url 目标IP
     * @param string $dst_port 目标端口
     * @param bool $is_udp 是否为UDP模式（默认false=TCP）
     * @param int|null $src_port 本地端口（null=随机）
     * @param int|null $pt RTP PT（默认96）
     * @param bool $use_ps 是否使用PS封装（默认true）
     * @param bool $only_audio 仅音频（默认false）
     * @param bool $rtcp 是否启用RTCP保活（仅UDP，默认false）
     * @param int|null $close_delay_ms 关闭延迟毫秒（可选）
     * @return array|null
     */
    public function startSendRtp(string $vhost, string $app, string $stream, string $ssrc, string $dst_url, string $dst_port, bool $is_udp = false, ?int $src_port = null, ?int $pt = null, bool $use_ps = true, bool $only_audio = false, bool $rtcp = false, ?int $close_delay_ms = null): ?array
    {
        $params = [
            'vhost' => '__defaultVhost__',
            'app' => $app,
            'stream' => $stream,
            'ssrc' => $ssrc,
            'dst_url' => $dst_url,
            'dst_port' => $dst_port,
            'is_udp' => $is_udp ? '1' : '0',
            'use_ps' => $use_ps ? '1' : '0',
            'only_audio' => $only_audio ? '1' : '0',
            'enable_origin_recv_limit' => '1',
        ];

        if ($src_port !== null) {
            $params['src_port'] = $src_port;
        }

        if ($pt !== null) {
            $params['pt'] = $pt;
        }

        // UDP模式下启用RTCP保活
        if ($is_udp) {
            $params['udp_rtcp_timeout'] = $rtcp ? '500' : '0';
        }

        if ($close_delay_ms !== null) {
            $params['close_delay_ms'] = $close_delay_ms;
        }

        $this->debugLog('ZLM API Request', [
            'api' => 'startSendRtp',
            'params' => $params,
        ]);

        $result = $this->request('startSendRtp', $params);

        if (!empty($result)) {
            return $result;
        }

        return null;
    }

    /**
     * 启动发送RTP被动模式（监听等待连接）
     * 用于TCP被动推流场景
     * 参考 WVP-PRO 实现
     *
     * @param string $vhost 虚拟主机
     * @param string $app 应用名
     * @param string $stream 流ID
     * @param string $ssrc RTP SSRC
     * @param int|null $src_port 本地端口（null=随机）
     * @param int|null $pt RTP PT（默认96）
     * @param bool $use_ps 是否使用PS封装（默认true）
     * @param bool $only_audio 仅音频（默认false）
     * @param bool $is_tcp 是否为TCP模式（默认true）
     * @param bool $rtcp 是否启用RTCP保活（仅UDP，默认false）
     * @param string|null $recv_stream_id 接收流ID（可选）
     * @param int|null $close_delay_ms 关闭延迟毫秒（可选）
     * @return array|null ['code' => 0, 'local_port' => 端口号]
     */
    public function startSendRtpPassive(string $vhost, string $app, string $stream, string $ssrc, ?int $src_port = null, ?int $pt = null, bool $use_ps = true, bool $only_audio = false, bool $is_tcp = true, bool $rtcp = false, ?string $recv_stream_id = null, ?int $close_delay_ms = null): ?array
    {
        $params = [
            'vhost' => $vhost ? $vhost : '__defaultVhost__',
            'app' => $app,
            'stream' => $stream,
            'ssrc' => $ssrc,
            'use_ps' => $use_ps ? '1' : '0',
            'only_audio' => $only_audio ? '1' : '0',
            'is_udp' => $is_tcp ? '0' : '1',
            'enable_origin_recv_limit' => '1',
        ];

        if ($src_port !== null) {
            $params['src_port'] = $src_port;
        }

        if ($pt !== null) {
            $params['pt'] = $pt;
        }

        if (!$is_tcp) {
            // UDP模式下开启RTCP保活
            $params['udp_rtcp_timeout'] = $rtcp ? '1' : '0';
        }

        if ($recv_stream_id !== null) {
            $params['recv_stream_id'] = $recv_stream_id;
        }

        if ($close_delay_ms !== null) {
            $params['close_delay_ms'] = $close_delay_ms;
        }

        $this->debugLog('ZLM API Request', [
            'api' => 'startSendRtpPassive',
            'params' => $params,
        ]);

        $result = $this->request('startSendRtpPassive', $params);

        if (!empty($result)) {
            return $result;
        }

        return null;
    }

    /**
     * 启动发送RTP Talk（语音对讲）
     * 参考 WVP-PRO 实现
     *
     * @param string $vhost 虚拟主机
     * @param string $app 应用名
     * @param string $stream 流ID
     * @param string $ssrc RTP SSRC
     * @param int|null $pt RTP PT（默认96）
     * @param bool $use_ps 是否使用PS封装（默认true），对应ZLM的type参数
     * @param bool $only_audio 仅音频（默认false）
     * @param string|null $recv_stream_id 接收流ID（可选）
     * @param int|null $close_delay_ms 关闭延迟毫秒（可选）
     * @return array|null ['code' => 0, 'local_port' => 端口号]
     */
    public function startSendRtpTalk(string $vhost, string $app, string $stream, string $ssrc, ?int $pt = null, bool $use_ps = true, bool $only_audio = false, ?string $recv_stream_id = null, ?int $close_delay_ms = null): ?array
    {
        $params = [
            'vhost' => '__defaultVhost__',
            'app' => $app,
            'stream' => $stream,
            'ssrc' => $ssrc,
            'type' => $use_ps ? '1' : '0',  // 注意：talk接口使用type而不是use_ps
            'only_audio' => $only_audio ? '1' : '0',
            'enable_origin_recv_limit' => '1',
        ];

        if ($pt !== null) {
            $params['pt'] = $pt;
        }

        if ($recv_stream_id !== null) {
            $params['recv_stream_id'] = $recv_stream_id;
        }

        if ($close_delay_ms !== null) {
            $params['close_delay_ms'] = $close_delay_ms;
        }

        $this->debugLog('ZLM API Request', [
            'api' => 'startSendRtpTalk',
            'params' => $params,
        ]);

        $result = $this->request('startSendRtpTalk', $params);

        if (!empty($result)) {
            return $result;
        }

        return null;
    }
    /**
     * 停止发送RTP
     * @param string $vhost
     * @param string $app
     * @param string $stream
     * @param string $ssrc
     * @return bool|null
     */
    public function stopSendRtp(string $vhost, string $app, string $stream, string $ssrc): ?bool
    {
        $params = [
            'vhost' => $vhost,
            'app' => $app,
            'stream' => $stream,
            'ssrc' => $ssrc,
        ];
        $this->debugLog('ZLM API Request', [
            'api' => 'stopSendRtp',
            'params' => $params,
        ]);
        $result = $this->request('stopSendRtp', $params);

        return $result && $result['code'] === 0;
    }

    /**
     * 使用 SendRtpDo 启动主动推流
     *
     * @param SendRtpDo $do
     * @return array|null
     */
    public function startSendRtpWithDo(SendRtpDo $do): ?array
    {
        return $this->startSendRtp(
            $do->getVhost(),
            $do->getApp(),
            $do->getStream(),
            $do->getSsrc(),
            $do->getDstUrl() ?? '',
            $do->getDstPort() ?? '',
            $do->isUdp(),
            $do->getSrcPort(),
            $do->getPt(),
            $do->isUsePs(),
            $do->isOnlyAudio(),
            $do->isRtcp(),
            $do->getCloseDelayMs()
        );
    }

    /**
     * 使用 SendRtpDo 启动被动推流
     *
     * @param SendRtpDo $do
     * @return array|null
     */
    public function startSendRtpPassiveWithDo(SendRtpDo $do): ?array
    {
        return $this->startSendRtpPassive(
            $do->getVhost(),
            $do->getApp(),
            $do->getStream(),
            $do->getSsrc(),
            $do->getSrcPort(),
            $do->getPt(),
            $do->isUsePs(),
            $do->isOnlyAudio(),
            $do->isTcp(),
            $do->isRtcp(),
            $do->getRecvStreamId(),
            $do->getCloseDelayMs()
        );
    }

    /**
     * 使用 SendRtpDo 启动语音对讲推流
     *
     * @param SendRtpDo $do
     * @return array|null
     */
    public function startSendRtpTalkWithDo(SendRtpDo $do): ?array
    {
        return $this->startSendRtpTalk(
            $do->getVhost(),
            $do->getApp(),
            $do->getStream(),
            $do->getSsrc(),
            $do->getPt(),
            $do->isUsePs(),
            $do->isOnlyAudio(),
            $do->getRecvStreamId(),
            $do->getCloseDelayMs()
        );
    }

    /**
     * HTTP请求封装
     *
     * @param string $api API名称
     * @param array $params 参数
     * @param string $method 请求方法
     * @return array|null
     */
    private function request(string $api, array $params = [], string $method = 'GET'): ?array
    {
        if ($this->secret) {
            $params['secret'] = $this->secret;
        }

        $url = "{$this->baseUrl}/{$api}";

        if ($api !== 'getMediaList') {
            $this->debugLog('ZLM API Request', [
                'api' => $api,
                'params' => $params,
            ]);
        }


        $result = $method === 'GET'
            ? $this->__GetRequest($url, $params)
            : $this->__PostRequest($url, $params);

        if ($api !== 'getMediaList') {
            $this->debugLog('ZLM API Response', [
                'api' => $api,
                'result' => $result,
            ]);
        }

        return $result;
    }

    private function curlRequest(string $url, array $options)
    {
        $ch = curl_init();

        curl_setopt_array($ch, $options + [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                ],
            ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error || $httpCode !== 200) {
            Log::channel('zlm')->error('ZLM API HTTP Error', [
                'url' => $url,
                'error' => $error,
                'http_code' => $httpCode,
                'response' => $response,
            ]);
            return null;
        }

        return json_decode($response, true);
    }

    private function __PostRequest(string $url, array $params = [])
    {
        return $this->curlRequest($url, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
        ]);
    }

    private function __GetRequest(string $url, array $params = [])
    {
        return $this->curlRequest(
            $url . '?' . http_build_query($params),
            []
        );
    }

    private function debugLog(string $msg, array $context = []): void
    {
        if ($this->debug) {
            Log::channel('zlm')->info($msg, $context);
        }
    }
}
