<?php

namespace CoreW\Sdk\ZLMediaKit;

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
     * 启动发送RTP
     * @param string $vhost
     * @param string $app
     * @param string $stream
     * @param string $ssrc
     * @param string $dst_url
     * @param string $dst_port
     * @param int $is_udp
     * @param int $src_port
     * @param int|null $pt
     * @param int $use_ps
     * @param int $only_audio
     * @return int|null
     */
    public function startSendRtp(string $vhost, string $app, string $stream, string $ssrc, string $dst_url, string $dst_port, int $is_udp = 0, ?int $src_port = null, ?int $pt = null, int $use_ps = 0, int $only_audio = 0): ?array
    {
//        | vhost |    Y     | 虚拟主机，例如__defaultVhost__ |
//    | app |    Y     | 应用名，例如 live |
//    | stream |    Y     | 流id，例如 test |
//    | ssrc |    Y     | 推流的rtp的ssrc,指定不同的ssrc可以同时推流到多个服务器 |
//    | dst_url |    Y     | 目标ip或域名 |
//    | dst_port |    Y     | 目标端口 |
//    | is_udp |    Y     | 是否为udp模式,否则为tcp模式 |
//    | src_port |    N     | 使用的本机端口，为0或不传时默认为随机端口 |
//    | pt |    N     | 发送时，rtp的pt（uint8_t）,不传时默认为96 |
//    | use_ps |    N     | 发送时，rtp的负载类型。为1时，负载为ps；为0时，为es；不传时默认为1 |
//    | only_audio |    N     | 当use_ps 为0时，有效。为1时，发送音频；为0时，发送视频；不传时默认为0 |
        $params = [
            'vhost' => $vhost,
            'app' => $app,
            'stream' => $stream,
            'ssrc' => $ssrc,
            'dst_url' => $dst_url,
            'dst_port' => $dst_port,
            'is_udp' => $is_udp,
        ];
        if ($src_port) {
            $params['src_port'] = $src_port;
        }
        if ($pt) {
            $params['pt'] = $pt;
        }
        if ($use_ps) {
            $params['use_ps'] = $use_ps;
        }
        if ($only_audio) {
            $params['only_audio'] = $only_audio;
        }
        $this->debugLog('ZLM API Request', [
            'api' => 'startSendRtp',
            'params' => $params,
        ]);
        $result = $this->request('startSendRtp', $params);

        if (!empty($result)) {
            //&& $result['code'] === 0 && isset($result['local_port']
            return $result;
        }

        return null;
    }

    public function startSendRtpPassive(string $vhost, string $app, string $stream, string $ssrc, ?int $src_port = null, ?int $pt = null, int $use_ps = 0, int $only_audio = 0): ?array
    {
//    | vhost |    Y     | 虚拟主机，例如__defaultVhost__ |
//    | app |    Y     | 应用名，例如 live |
//    | stream |    Y     | 流id，例如 test |
//    | ssrc |    Y     | 推流的rtp的ssrc,指定不同的ssrc可以同时推流到多个服务器 |
//    | src_port |    N     | 使用的本机端口，为0或不传时默认为随机端口 |
//    | pt |    N     | 发送时，rtp的pt（uint8_t）,不传时默认为96 |
//    | use_ps |    N     | 发送时，rtp的负载类型。为1时，负载为ps；为0时，为es；不传时默认为1 |
//    | only_audio |    N     | 当use_ps 为0时，有效。为1时，发送音频；为0时，发送视频；不传时默认为0 |
        $params = [
            'vhost' => $vhost,
            'app' => $app,
            'stream' => $stream,
            'ssrc' => $ssrc,
        ];
        if ($src_port) {
            $params['src_port'] = $src_port;
        }
        if ($pt) {
            $params['pt'] = $pt;
        }
        if ($use_ps) {
            $params['use_ps'] = $use_ps;
        }
        if ($only_audio) {
            $params['only_audio'] = $only_audio;
        }
        $this->debugLog('ZLM API Request', [
            'api' => 'startSendRtp',
            'params' => $params,
        ]);
        $result = $this->request('startSendRtpPassive', $params);

        if (!empty($result)) {
            //&& $result['code'] === 0 && isset($result['local_port']
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
