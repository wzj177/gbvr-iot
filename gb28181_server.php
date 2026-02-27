<?php

require_once __DIR__ . '/vendor/autoload.php';

// 创建SIP服务器实例
use Gb28181\GateWay\Handlers\GB28181Handler;

$config = require_once __DIR__ . '/config/gb28181.php';

$sipOptions = [
    'ua' => $config['user_agent'],
    'ip' => $config['listen_addr'],
    'port' => $config['sip_port'],
    'mode' => $config['transport'],
    'debug' => $config['debug'],
    'task_worker_num' => $config['task_worker_num'],
    'long_task_worker_num' => 1,
    'pid_file' => $config['pid_file'],
    'sipId' => $config['server_id'],
    'sipRealm' => $config['server_domain'],
    'timer_interval' => $config['timer_interval'],
];

// NAT 穿透：当配置了 public_ip 时，设置 eXosip 的 masquerade 地址
// 这会修正 SIP Contact/Via 头中的 IP 地址，使其使用公网 IP 而非内网 IP
// 解决场景示例：
//   修正前: Contact: <sip:34020000002000000001@192.168.31.119:15060>
//   修正后: Contact: <sip:34020000002000000001@10.20.2.95:15060>
if (!empty($config['public_ip'])) {
    $sipOptions['public_ip'] = $config['public_ip'];
}

$sipServer = new ExoSip($sipOptions);

if (!empty($argv[1]) && filter_var($argv[1], FILTER_VALIDATE_IP)) {
    $config['zlm']['media_server_ip'] = $argv[1];
}

// 创建GB28181事件处理器
$gb28181 = new GB28181Handler($sipServer, [
    'server_id' => $config['server_id'],
    'server_domain' => $config['server_domain'],
    'device_password' => $config['device_password'],
    'authentication' => $config['authentication'],
    'sip_username' => $config['sip_username'],
    'heartbeat_timeout' => $config['heartbeat_timeout'],
    'register_expires' => $config['register_expires'],
    'catalog_auto_query' => $config['catalog_auto_query'],
    'check_interval' => $config['timer_interval'],
    'check_offline_device_interval' => $config['check_offline_device_interval'],
    'max_devices' => $config['max_devices'],
    'encoding_type' => $config['encoding_type'],
    'debug' => $config['debug'],
    'log_file' => $config['log_file'] ?? 'php://stdout',
    'log_level' => $config['log_level'] ?? 'INFO',
    'log_max_days' => $config['log_max_days'] ?? 30,
    'redis' => $config['redis'],
    'api_hock_url' => $config['api']['hock_url'],
    'api_pull_url' => $config['api']['pull_url'],
    'api_hock_token' => $config['api']['token'],
]);
// 绑定GB28181事件处理器
$gb28181->bindEvents();

// 打印启动信息
echo "=================================\n";
echo "  GB28181  Server\n";
echo "=================================\n";
echo "Server ID: {$config['server_id']}\n";
echo "Domain: {$config['server_domain']}\n";
echo "Listening on: {$config['listen_addr']}:{$config['sip_port']}\n";
echo "Transport: {$config['transport']}\n";
echo "Log file: {$config['log_file']}\n";
echo "Log level: {$config['log_level']}\n";
echo "=================================\n\n";

echo "[INFO] 服务器已启动，等待设备接入...\n\n";


// 启动服务器（阻塞式运行）
$sipServer->run();