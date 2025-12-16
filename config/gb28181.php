<?php
/**
 * GB28181 SIP 服务器配置文件
 *
 * 参考标准：
 * - GB/T 28181-2016 国标视频监控联网系统规范
 * - 国标6.1.2：domain 宜采用ID统一编码的前十位
 * - 国标附录D：前8位为中心编码（省、市、区、基层），后2位为行业编码
 *
 * 配置说明：
 * - server_id: 20位国标编码，服务器设备ID
 * - server_domain: 前10位编码，作为SIP域
 * - device_password: 统一接入密码，所有设备使用相同密码
 */

return [
    // ========== SIP 服务器基本配置 ==========

    /**
     * SIP 监听端口
     * 默认: 5060 (UDP/TCP)
     */
    'sip_port' => 15060,

    /**
     * SIP 传输协议
     * 可选值: 'UDP', 'TCP'
     */
    'transport' => 'UDP',

    /**
     * 监听地址
     * '0.0.0.0' 表示监听所有网卡
     */
    'listen_addr' => '0.0.0.0',

    /**
     * 公网IP地址 (用于NAT穿透,设置SIP Via/Contact头) - 非必填
     *
     * 这是GB28181平台自己的IP,用于SIP信令通信
     *
     * 使用场景:
     * - 服务器在 NAT 后面,需要设置公网IP
     * - 多网卡环境,需要指定特定网卡的IP
     *
     * 示例: '192.168.31.119'
     * 留空则自动检测第一个非回环IP
     */
    'public_ip' => '',

    /**
     * PID 文件
     * 默认: /tmp/gb28181_server.pid
     */
    'pid_file' => runtime_path('gb28181_server.pid'),

    /**
     * 任务进程数
     * 默认: 4
     */
    'task_worker_num' => 2,

    // ========== GB28181 国标编码 ==========

    /**
     * 服务器 SIP ID (20位国标编码)
     *
     * 编码规则：
     * - 前8位: 中心编码 (3402000000 = 安徽省合肥市)
     *   3401: 安徽省
     *   340200: 安徽省合肥市
     * - 第9-10位: 行业编码 (00 = 通用)
     * - 第11-13位: 类型编码 (200 = 业务分组/虚拟组织)
     * - 第14-20位: 序号
     */
    'server_id' => '34020000002000000001',

    /**
     * SIP 域 (Realm)
     * 根据国标6.1.2，使用ID的前10位
     */
    'server_domain' => '3402000000',

    // ========== 设备认证配置 ==========

    /**
     * 统一设备接入密码
     * 所有GB28181设备在NVR/IPC上配置相同的密码
     * 移除此配置项将不进行密码校验
     */
    'device_password' => '12345678',

    /**
     * 是否启用认证
     * true: 强制 Digest 认证
     * false: 允许无认证接入（不安全，仅测试用）
     */
    'authentication' => true,

    /**
     * 认证用户名（可选）
     * 部分厂商设备需要配置用户名
     */
    'sip_username' => 'admin',

    // ========== 注册与心跳配置 ==========

    /**
     * 设备注册有效期（秒）
     * 设备需要在此时间内重新注册
     */
    'register_expires' => 3600,

    /**
     * 心跳间隔（秒）
     * 设备发送心跳的标准间隔
     */
    'keepalive_interval' => 60,

    /**
     * 心跳超时时间（秒）
     * 超过此时间未收到心跳，标记设备离线
     */
    'heartbeat_timeout' => 180,

    /**
     * 心跳丢失次数阈值
     * 连续丢失多少次心跳后标记设备离线
     */
    'keepalive_lost_number' => 3,

    // ========== 设备管理配置 ==========

    /**
     * 自动查询设备目录
     * 设备注册成功后自动查询通道目录
     */
    'catalog_auto_query' => true,

    /**
     * 设备检查间隔（秒）
     * 定时检查设备心跳超时
     */
    'timer_interval' => 30,


    /**
     * 检测离线设备间隔（秒）
     * 定时检查设备是否离线
     */
    'check_offline_device_interval' => 3600,

    /**
     * 最大设备连接数
     */
    'max_devices' => 10000,

    // ========== 字符编码配置 ==========

    /**
     * GB28181 消息编码
     * 可选值: 'GB2312', 'UTF-8'
     * 国标规定使用 GB2312，但部分厂商使用 UTF-8
     */
    'encoding_type' => 'GB2312',

    // ========== 调试与日志 ==========

    /**
     * 调试模式
     * 开启后打印详细的SIP消息和认证过程
     */
    'debug' => true,

    /**
     * 日志级别
     * 可选值: 'DEBUG', 'INFO', 'WARNING', 'ERROR'
     */
    'log_level' => 'INFO',

    // ========== 高级配置 ==========

    /**
     * 免认证设备白名单（可选）
     * 特定设备可以跳过密码认证
     * 格式: ['device_id' => true, ...]
     */
    'no_authentication_required' => [
        // '34020000001320000001' => true,
    ],

    /**
     * IPv6 支持
     */
    'ipv6_enable' => false,

    /**
     * IPv6 监听地址
     */
    'ipv6_address' => '::',

    /**
     * GB28181 版本
     * 可选值: 'GB-2011', 'GB-2016'
     */
    'gb_version' => 'GB-2016',

    /**
     * SIP User-Agent 标识
     */
    'user_agent' => 'PHP GB28181 Server/1.0',

    /**
     * 最大SIP消息大小（字节）
     */
    'max_message_size' => 65535,

    /**
     * SIP 事务超时时间（毫秒）
     */
    'transaction_timeout' => 32000,

    // ========== 持久化配置 ==========

    /**
     * 设备缓存文件路径
     * 用于重启后恢复设备列表
     */
    'device_cache_file' => '/tmp/gb28181_devices.cache',

    'redis' => [
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 11,
        'prefix' => 'gbvr_iot_gb_gateway_',
        'queue_name' => 'gb28181:commands'
    ],


    // ========== ZLMediaKit 流媒体服务器配置 ==========

    /**
     * 关键配置: ZLM流媒体服务器
     *
     * media_server_ip: ZLM服务器的IP地址
     *   - 设备会向这个IP推送RTP流!
     *   - INVITE SDP中的 c=IN IP4 将使用此IP
     *   - 如果ZLM和GB28181在同一台机器,设置为 public_ip 的值
     *   - 如果ZLM在另一台机器,设置为ZLM服务器的实际IP
     *
     * 示例:
     *   'media_server_ip' => '192.168.31.119',  // 同机器部署
     *   'media_server_ip' => '192.168.1.200',  // ZLM在另一台机器
     */
    'zlm' => [
        'media_server_ip' => env('ZLM_SERVER_IP', '127.0.0.1'),  // ← 设备会向这个IP推流!
        'media_server_port_start' => 30000,
        'media_server_port_end' => 40000,
    ],
    'api' => [
        'hock_url' => 'http://127.0.0.1:8886/api/v2/gb/server/hock',
        'pull_url' => 'http://127.0.0.1:8886/api/v2/gb/devices/pull',
        'token' => '$2y$10$DfjYrMs2Vvl2t3xw65LQXO3dZbs085qr7XJMzAnhSQixKnPejzgTm'
    ]
];
