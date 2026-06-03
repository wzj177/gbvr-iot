<?php

require_once __DIR__ . '/vendor/autoload.php';

// 创建SIP服务器实例
use Gb28181\GateWay\Handlers\GB28181Handler;

// 始终读取本地文件获取基础连接信息（bootstrap 配置）
$bootstrapConfig = require_once __DIR__ . '/config/gb28181.php';

// 解析 --gateway-id CLI 参数
$gatewayId = null;
foreach ($argv as $i => $arg) {
    if (str_starts_with($arg, '--gateway-id=')) {
        $gatewayId = substr($arg, strlen('--gateway-id='));
        break;
    }
    if ($arg === '--gateway-id' && isset($argv[$i + 1])) {
        $gatewayId = $argv[$i + 1];
        break;
    }
}

$config = $bootstrapConfig;

if ($gatewayId) {
    // 集群模式：通过 HTTP 从 API 拉取完整配置
    $apiBaseUrl = rtrim($bootstrapConfig['api']['hock_url'], '/');
    // hook URL: .../server/hook → config URL: .../gateway/config
    $apiBasePath = dirname(dirname($apiBaseUrl)); // 去掉 /server/hook → 得到 /api/v2/gb
    $configUrl = $apiBasePath . '/gateway/config?gateway_id=' . urlencode($gatewayId);

    echo "[INFO] 集群模式: 从 API 拉取网关配置 gateway_id={$gatewayId}\n";
    echo "[INFO] 配置URL: {$configUrl}\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $configUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Token: ' . $bootstrapConfig['api']['token'],
    ]);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        echo "[ERROR] 拉取网关配置失败: {$error}\n";
        exit(1);
    }

    $result = json_decode($response, true);
    if (empty($result) || !isset($result['code']) || $result['code'] != 0 || empty($result['data'])) {
        echo "[ERROR] 拉取网关配置失败: 无效响应\n";
        echo "[DEBUG] Response: " . substr($response, 0, 500) . "\n";
        exit(1);
    }

    $remoteConfig = $result['data'];

    // 用远程配置覆盖本地配置
    $config['server_id'] = $remoteConfig['server_id'];
    $config['server_domain'] = $remoteConfig['server_domain'];
    $config['sip_port'] = $remoteConfig['sip_port'];
    $config['transport'] = $remoteConfig['transport'] ?? 'UDP';
    $config['public_ip'] = $remoteConfig['public_ip'] ?? '';
    $config['device_password'] = $remoteConfig['device_password'] ?? '';
    $config['authentication'] = $remoteConfig['authentication'] ?? true;
    $config['sip_username'] = $remoteConfig['sip_username'] ?? '';
    $config['register_expires'] = $remoteConfig['register_expires'] ?? 3600;
    $config['heartbeat_timeout'] = $remoteConfig['heartbeat_timeout'] ?? 180;
    $config['keepalive_lost_number'] = $remoteConfig['keepalive_lost_number'] ?? 3;
    $config['catalog_auto_query'] = $remoteConfig['catalog_auto_query'] ?? true;
    $config['encoding_type'] = $remoteConfig['encoding_type'] ?? 'GB2312';
    $config['task_worker_num'] = $remoteConfig['task_worker_num'] ?? 4;
    $config['timer_interval'] = $remoteConfig['timer_interval'] ?? 60;
    $config['max_devices'] = $remoteConfig['max_devices'] ?? 10000;
    $config['broadcast_push_after_ack'] = $remoteConfig['broadcast_push_after_ack'] ?? true;
    $config['debug'] = $remoteConfig['debug'] ?? false;
    $config['log_level'] = $remoteConfig['log_level'] ?? 'INFO';

    // 注入 gateway_id 到配置（供 GB28181Handler 使用）
    $config['gateway_id'] = $remoteConfig['gateway_id'];

    // 注入 mq_type 到配置（供 GB28181Handler 创建 Transport 使用）
    $config['mq_type'] = $remoteConfig['mq_type'] ?? 'redis';

    // 注入远程 Redis 配置（包含 gateway 专属 queue_name）
    if (!empty($remoteConfig['redis_config'])) {
        $config['redis'] = $remoteConfig['redis_config'];
    }

    // 注入远程 MQ 配置
    if (!empty($remoteConfig['mq_config'])) {
        $config['mq_config'] = $remoteConfig['mq_config'];
    }

    // 注入远程 API 回调配置
    if (!empty($remoteConfig['api_config'])) {
        $config['api'] = array_merge($config['api'], $remoteConfig['api_config']);
    }

    // 注入心跳检查间隔
    $config['check_interval'] = $remoteConfig['check_interval'] ?? $config['timer_interval'];

    echo "[INFO] 网关配置拉取成功: server_id={$remoteConfig['server_id']}, mq_type={$remoteConfig['mq_type']}\n";
} else {
    // 单机模式：使用本地 config/gb28181.php 配置（向后兼容）
    echo "[INFO] 单机模式: 使用本地配置文件\n";
}

$sipOptions = [
    'ua' => $config['user_agent'],
    'ip' => $config['listen_addr'],
    'port' => $config['sip_port'],
    'mode' => $config['transport'],
    'debug' => $config['debug'],
    'task_worker_num' => $config['task_worker_num'],
    'long_task_worker_num' => 2,
    'pid_file' => $config['pid_file'],
    'sipId' => $config['server_id'],
    'sipRealm' => $config['server_domain'],
    'timer_interval' => $config['timer_interval'],
];

// NAT 穿透：当配置了 public_ip 时，设置 eXosip 的 masquerade 地址
// 这会修正 SIP Contact/Via 头中的 IP 地址，使其使用公网 IP 而非内网 IP
// 解决场景示例：
// 修正前: Contact: <sip:34020000002000000001@192.168.31.119:15060>
// 修正后: Contact: <sip:34020000002000000001@10.20.2.95:15060>
if (!empty($config['public_ip'])) {
    $sipOptions['public_ip'] = $config['public_ip'];
}

$sipServer = new ExoSip($sipOptions);

// 支持 CLI 传入 ZLM IP（兼容旧用法）
if (!empty($argv[1]) && filter_var($argv[1], FILTER_VALIDATE_IP)) {
    $config['zlm']['media_server_ip'] = $argv[1];
}

// 直接使用完整 $config，只补充 Handler 需要的别名 key
$handlerConfig                   = $config;
$handlerConfig['check_interval'] = $config['timer_interval'];
$handlerConfig['api_hock_url']   = $config['api']['hock_url'];
$handlerConfig['api_pull_url']   = $config['api']['pull_url'];
$handlerConfig['api_hock_token'] = $config['api']['token'];
$handlerConfig['mq_type']        = $config['mq_type'] ?? $config['queue'] ?? 'redis';
$handlerConfig['sip_host']       = $config['listen_addr'];

// RabbitMQ 配置展开到 mq_config
if (($handlerConfig['mq_type'] === 'rabbitmq') && !empty($config['rabbitmq'])) {
    $handlerConfig['mq_config'] = $config['rabbitmq'];
}

// 集群模式：gateway_id 和专属队列已由拉取的远程配置注入到 $config 中
if ($gatewayId) {
    $handlerConfig['gateway_id'] = $gatewayId;
    if (!empty($config['mq_config'])) {
        $handlerConfig['mq_config'] = $config['mq_config'];
    }
}

$gb28181 = new GB28181Handler($sipServer, $handlerConfig);
// 绑定GB28181事件处理器
$gb28181->bindEvents();

// 打印启动信息
echo "=================================\n";
echo " GB28181 Server\n";
echo "=================================\n";
echo "Server ID: {$config['server_id']}\n";
echo "Domain: {$config['server_domain']}\n";
echo "Listening on: {$config['listen_addr']}:{$config['sip_port']}\n";
echo "Transport: {$config['transport']}\n";
if ($gatewayId) {
    echo "Gateway ID: {$gatewayId}\n";
    echo "MQ Type: " . ($config['mq_type'] ?? 'redis') . "\n";
    echo "Queue: " . ($config['redis']['queue_name'] ?? 'gb28181:commands') . "\n";
}
echo "Log file: {$config['log_file']}\n";
echo "Log level: {$config['log_level']}\n";
echo "=================================\n\n";

echo "[INFO] 服务器已启动，等待设备接入...\n\n";


// 启动服务器（阻塞式运行）
$sipServer->run();
