<?php

/**
 * ONVIF 网关配置（Swoole 版，入口 swoole-gbs/onvif-server.php）
 *
 * 三条核心流程：
 *  - listen         ：WS-Discovery 组播 239.255.255.250:3702（Hello + 周期 Probe）
 *  - gateway-to-api ：onvif_device_found / onvif_cmd_after scene 上报（默认复用 gb28181 的 hook 地址）
 *  - api-to-gateway ：onvif:commands:{priority|normal} 队列命令消费（Redis db11 + gb_gateway 前缀，与 GB 同库）
 */

return [
    // 网关标识（集群多网关时命令队列按此分片，空则用公共队列）
    'gateway_id'          => '',

    // ==== 发现（listen） ====
    'discovery_enabled'   => true,
    'bind_ip'             => '0.0.0.0',
    'probe_interval'      => 60,      // 周期 Probe 秒
    'probe_types'         => ['dn:NetworkVideoTransmitter'],
    'dedup_ttl'           => 300,     // 同一 uuid 上报去重窗口（秒）

    // ==== 设备接入默认值（命令 params 可覆盖 username/password） ====
    'default_username'    => 'admin',
    'default_password'    => '',
    'http_timeout'        => 5,

    // ==== api-to-gateway 命令队列（与 gb28181.php 的 redis 同库同前缀） ====
    'redis'               => [
        'host'       => '127.0.0.1',
        'password'   => null,
        'port'       => 6379,
        'database'   => 11,
        'prefix'     => 'gbvr_iot_gb_gateway_',
        'queue_name' => 'onvif:commands',
    ],

    // ==== gateway-to-api hook 上报 ====
    'api'                 => [
        'hock_url' => 'http://127.0.0.1:8886/api/v2/gb/server/hook',
        'token'    => 'd6b6183b8735f51ab251ea9f47580c208b6ea8889d871d80e1d032e191111a61',
    ],
];
