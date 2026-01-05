<?php
/**
 * ZLMediaKit 配置
 */
return [
    // ZLM 可执行文件
    'executable' => env('ZLM_EXECUTABLE', '/usr/local/bin/MediaServer'),
    // ZLM 配置文件
    'config_file' => env('ZLM_CONFIG_FILE', config_path('zlm/config.ini')),

    'ssl_file' => env('ZLM_SSL_FILE', config_path('zlm/ssl.pem')),

    'log_dir' => env('ZLM_LOG_DIR', runtime_path('logs/zlm')),
    'pid_file' => env('ZLM_PID_FILE', runtime_path('zlm.pid')),

    'server_ip' => env('ZLM_SERVER_IP', '127.0.0.1'),
    // ZLM HTTP API地址
    'host' => env('ZLM_HOST', '127.0.0.1'),
    
    // ZLM HTTP API端口
    'port' => env('ZLM_PORT', 80),
    
    // ZLM API密钥
    'secret' => env('ZLM_SECRET', ''),
    
    // RTP服务器端口范围
    'rtp_port_start' => env('ZLM_RTP_PORT_START', 30000),
    'rtp_port_end' => env('ZLM_RTP_PORT_END', 40000),
    
    // 默认RTP传输模式
    // 0: UDP模式（延迟最低，局域网推荐）
        // 1: TCP被动模式（公网推荐，设备主动连接）
    // 2: TCP主动模式（服务器连接设备，需设备端口映射）
    'default_tcp_mode' => env('ZLM_DEFAULT_TCP_MODE', 1),
    
    // 流媒体服务器标识
    'media_server_id' => env('ZLM_MEDIA_SERVER_ID', 'default'),
    
    // 调试模式
    'debug' => env('ZLM_DEBUG', false),
    
    // 播放地址配置
    'play_urls' => [
        // RTSP播放地址模板
        'rtsp' => env('ZLM_RTSP_URL', 'rtsp://127.0.0.1/rtp/{stream_id}'),
        
        // RTMP播放地址模板
        'rtmp' => env('ZLM_RTMP_URL', 'rtmp://127.0.0.1/rtp/{stream_id}'),
        
        // HTTP-FLV播放地址模板
        'flv' => env('ZLM_FLV_URL', 'http://127.0.0.1/rtp/{stream_id}.live.flv'),
        
        // WebRTC播放地址模板
        'webrtc' => env('ZLM_WEBRTC_URL', 'http://127.0.0.1/rtp/{stream_id}'),
        
        // HLS播放地址模板
        'hls' => env('ZLM_HLS_URL', 'http://127.0.0.1/rtp/{stream_id}/hls.m3u8'),
    ],
];
