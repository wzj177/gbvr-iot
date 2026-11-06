<?php

namespace app\command;

use Gb28181\GateWay\Handlers\GB28181Handler;
use Gb28181\GateWay\Swoole\ExoSip;
use Gb28181\GateWay\Swoole\SimpleLogger;
use Gb28181\GateWay\Swoole\Supervisor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Swoole 协程版国标信令服务（与扩展版 gb28181:server 并存，互不影响）
 *
 * 业务层与扩展版完全同一套（GB28181Handler 等），仅 SIP 引擎替换为
 * Gb28181\GateWay\Swoole\ExoSip（自解析协议栈 + 协程）。
 *
 * 用法：
 *   php webman gb28181:swoole start                # UDP 前台启动（master 监督 + worker）
 *   php webman gb28181:swoole start --tcp          # TCP 方式
 *   php webman gb28181:swoole start --gateway-id=gbs_node_02   # 集群模式拉远程配置
 *   php webman gb28181:swoole stop|restart|status
 */
class Gb28181SwooleServer extends Command
{
    protected static $defaultName = 'gb28181:swoole {action}';

    protected static $defaultDescription = '国标信令服务(Swoole协程版): 启动、停止、重启、查看状态';

    private bool $debug = false;

    private string $pidFile = '';

    protected function configure() : void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, '启动动作：start, stop, restart, status')
            ->addOption('tcp', 't', InputOption::VALUE_NONE, '以TCP方式启动')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, '是否开启调试模式')
            ->addOption('gateway-id', 'g', InputOption::VALUE_REQUIRED, '集群模式：网关ID（从API拉取远程配置）');
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->debug   = (bool)$input->getOption('debug');
        $this->pidFile = runtime_path('gb28181_swoole_server.pid');

        $action = $input->getArgument('action');
        match ($action) {
            'start'   => $this->startServer($input, $output),
            'stop'    => $this->stopServer($input, $output),
            'restart' => $this->restartServer($input, $output),
            'status'  => $this->statusServer($input, $output),
            default   => $output->writeln("[ERROR] 无效的启动动作：{$action}"),
        };
        return self::SUCCESS;
    }

    // =====================================================================
    // start
    // =====================================================================

    private function startServer(InputInterface $input, OutputInterface $output) : void
    {
        if (!extension_loaded('swoole')) {
            $output->writeln('<error>[FATAL] 缺少 ext-swoole（需协程 Socket/Channel，4.5+）</error>');
            return;
        }

        // EXOSIP_* 事件类型常量（业务层 handleResponse 使用，与 exosip.stub.php 取值一致）
        require_once base_path() . '/Gb28181Gateway/src/Swoole/constants.php';

        $config    = config('gb28181');
        $gatewayId = $input->getOption('gateway-id');

        if ($input->getOption('tcp')) {
            $config['transport'] = 'TCP';
        }
        if ($this->debug) {
            $config['debug'] = true;
        }

        // 集群模式：从 API 拉取远程配置（与 gb28181_server.php 相同协议）
        if ($gatewayId) {
            $remote = $this->pullGatewayConfig($config, $gatewayId, $output);
            if ($remote === null) {
                return;
            }
            $config = $remote;
            $config['gateway_id'] = $gatewayId;
        }

        $config = $this->applyEnvOverrides($config);

        // Swoole 版强制协程 Redis 传输（phpredis 阻塞 BLPOP 会挂起协程调度器）
        if (($config['mq_type'] ?? 'redis') === 'redis') {
            $config['mq_type'] = 'redis_co';
        }

        $sipOptions = [
            'ua'         => $config['user_agent'],
            'ip'         => $config['listen_addr'],
            'port'       => (int)$config['sip_port'],
            'mode'       => $config['transport'],
            'debug'      => (bool)($config['debug'] ?? false),
            'task_worker_num' => (int)($config['task_worker_num'] ?? 4),
            'long_task_worker_num' => (int)($config['long_task_worker_num'] ?? 1),
            'sipId'      => $config['server_id'],
            'sipRealm'   => $config['server_domain'],
            'timer_interval' => (int)($config['timer_interval'] ?? 60),
            'username'   => $config['sip_username'] ?? '',
            'password'   => $config['device_password'] ?? '',
            'logger'     => new SimpleLogger(),
        ];
        if (!empty($config['public_ip'])) {
            $sipOptions['public_ip'] = $config['public_ip'];
        }

        $handlerConfig                   = $config;
        $handlerConfig['check_interval'] = $config['timer_interval'];
        $handlerConfig['api_hock_url']   = $config['api']['hock_url'];
        $handlerConfig['api_pull_url']   = $config['api']['pull_url'];
        $handlerConfig['api_hock_token'] = $config['api']['token'];
        $handlerConfig['sip_host']       = $config['listen_addr'];
        if (($handlerConfig['mq_type'] === 'rabbitmq') && !empty($config['rabbitmq'])) {
            $handlerConfig['mq_config'] = $config['rabbitmq'];
        }
        if (!empty($config['mq_config'])) {
            $handlerConfig['mq_config'] = $config['mq_config'];
        }

        $output->writeln("=================================");
        $output->writeln('  GB28181 Server (Swoole Coroutine)');
        $output->writeln("=================================");
        $output->writeln("Server ID: {$config['server_id']}");
        $output->writeln("Domain: {$config['server_domain']}");
        $output->writeln("Listening on: {$config['listen_addr']}:{$config['sip_port']} ({$config['transport']})");
        $output->writeln('Swoole: ' . SWOOLE_VERSION);
        $output->writeln('Queue: ' . ($config['redis']['queue_name'] ?? 'gb28181:commands') . ' (' . $handlerConfig['mq_type'] . ')');
        $output->writeln("Redis: {$config['redis']['host']}:{$config['redis']['port']} db={$config['redis']['database']}");
        $output->writeln("API Hook: {$config['api']['hock_url']}");
        if (!empty($config['gateway_id'])) {
            $output->writeln("Gateway ID: {$config['gateway_id']}");
        }
        $output->writeln("PID File: {$this->pidFile}");
        $output->writeln("=================================\n");
        $output->writeln('[INFO] 服务器已启动，等待设备接入...(Ctrl+C 停止)' . "\n");

        // master 监督 + worker（协程引擎 + 同一套业务层）
        (new Supervisor(new SimpleLogger()))->run(
            'gb28181-swoole',
            $this->pidFile,
            function () use ($sipOptions, $handlerConfig) {
                $sipServer = new ExoSip($sipOptions);
                $gb28181   = new GB28181Handler($sipServer, $handlerConfig);
                $gb28181->bindEvents();
                $sipServer->run();   // 阻塞于协程容器
            }
        );
    }

    // =====================================================================
    // stop / restart / status
    // =====================================================================

    private function stopServer(InputInterface $input, OutputInterface $output) : void
    {
        $masterPid = $this->readMasterPid($output);
        if ($masterPid <= 0) {
            return;
        }
        if (!posix_kill($masterPid, SIGTERM)) {
            $output->writeln("[ERROR] 无法停止服务器: {$masterPid}");
            return;
        }
        $output->writeln('[INFO] 服务器已停止');
    }

    private function restartServer(InputInterface $input, OutputInterface $output) : void
    {
        if ($this->readMasterPid($output, false) > 0) {
            $this->stopServer($input, $output);
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline && posix_kill($this->readMasterPidSilent(), 0)) {
                usleep(200000);
            }
        }
        $this->startServer($input, $output);
    }

    private function statusServer(InputInterface $input, OutputInterface $output) : void
    {
        $masterPid = $this->readMasterPid($output);
        if ($masterPid <= 0) {
            return;
        }
        if (!posix_kill($masterPid, 0)) {
            $output->writeln('[ERROR] 进程不存在（PID 文件残留）：' . $this->pidFile);
            return;
        }
        $output->writeln('=============================================');
        $output->writeln('  GB28181 Swoole Server Status');
        $output->writeln('=============================================');
        $output->writeln("Master PID: {$masterPid}");
        $rss = @file_get_contents("/proc/{$masterPid}/status");
        if ($rss && preg_match('/VmRSS:\s+(\d+)\s+kB/', $rss, $m)) {
            $output->writeln('Memory: ' . round((int)$m[1] / 1024, 2) . ' MB');
        }
        // 找 worker 子进程
        foreach (explode("\n", (string)@shell_exec("ps -o pid,ppid,rss,etime -x | grep {$masterPid} | grep -v grep") ?? '') as $line) {
            if (preg_match('/^\s*(\d+)\s+' . $masterPid . '\s+(\d+)\s+(\S+)/', $line, $m)) {
                $output->writeln("Worker PID: {$m[1]}, Memory: " . round((int)$m[2] / 1024, 2) . " MB, Uptime: {$m[3]}");
            }
        }
        $output->writeln('=============================================');
    }

    // =====================================================================
    // 辅助
    // =====================================================================

    private function readMasterPid(OutputInterface $output, bool $verbose = true) : int
    {
        if (!file_exists($this->pidFile)) {
            if ($verbose) {
                $output->writeln("错误: PID 文件不存在: {$this->pidFile}");
                $output->writeln('提示: 服务器可能未运行');
            }
            return 0;
        }
        $pid = (int)trim((string)file_get_contents($this->pidFile));
        if ($pid <= 0 && $verbose) {
            $output->writeln("[ERROR] 无效的 PID: {$pid}");
        }
        return $pid;
    }

    private function readMasterPidSilent() : int
    {
        return is_file($this->pidFile) ? (int)trim((string)file_get_contents($this->pidFile)) : 0;
    }

    /**
     * 集群模式：GET {api_base}/gateway/config?gateway_id=xxx（与 gb28181_server.php 相同协议）
     */
    private function pullGatewayConfig(array $config, string $gatewayId, OutputInterface $output) : ?array
    {
        $apiBaseUrl  = rtrim($config['api']['hock_url'], '/');
        $configUrl   = dirname(dirname($apiBaseUrl)) . '/gateway/config?gateway_id=' . urlencode($gatewayId);
        $output->writeln("[INFO] 集群模式: 拉取网关配置 gateway_id={$gatewayId}");

        $ch = curl_init($configUrl);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Token: ' . $config['api']['token']],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            $output->writeln("[ERROR] 拉取网关配置失败: {$error}");
            return null;
        }
        $result = json_decode((string)$response, true);
        if (empty($result) || ($result['code'] ?? -1) != 0 || empty($result['data'])) {
            $output->writeln('[ERROR] 拉取网关配置失败: 无效响应 ' . substr((string)$response, 0, 300));
            return null;
        }
        $remote = $result['data'];
        foreach ([
            'server_id', 'server_domain', 'sip_port', 'public_ip', 'device_password', 'sip_username',
            'register_expires', 'heartbeat_timeout', 'keepalive_lost_number', 'catalog_auto_query',
            'encoding_type', 'task_worker_num', 'timer_interval', 'max_devices',
            'broadcast_push_after_ack', 'debug', 'log_level', 'gateway_id', 'mq_type',
        ] as $key) {
            if (array_key_exists($key, $remote)) {
                $config[$key] = $remote[$key];
            }
        }
        $config['transport'] = $remote['transport'] ?? ($config['transport'] ?? 'UDP');
        if (!empty($remote['redis_config'])) {
            $config['redis'] = $remote['redis_config'];
        }
        if (!empty($remote['mq_config'])) {
            $config['mq_config'] = $remote['mq_config'];
        }
        if (!empty($remote['api_config'])) {
            $config['api'] = array_merge($config['api'], $remote['api_config']);
        }
        return $config;
    }

    /**
     * 环境变量覆盖（docker / compose 部署）
     */
    private function applyEnvOverrides(array $config) : array
    {
        $envMap = [
            'GB_REDIS_HOST'      => ['redis', 'host'],
            'GB_REDIS_PORT'      => ['redis', 'port'],
            'GB_REDIS_PASSWORD'  => ['redis', 'password'],
            'GB_REDIS_DATABASE'  => ['redis', 'database'],
            'GB_API_HOCK_URL'    => ['api', 'hock_url'],
            'GB_API_PULL_URL'    => ['api', 'pull_url'],
            'GB_API_TOKEN'       => ['api', 'token'],
            'GB_SIP_PORT'        => ['sip_port'],
            'GB_SERVER_ID'       => ['server_id'],
            'GB_SERVER_DOMAIN'   => ['server_domain'],
            'GB_DEVICE_PASSWORD' => ['device_password'],
            'GB_LOG_LEVEL'       => ['log_level'],
        ];
        foreach ($envMap as $env => $path) {
            $v = getenv($env);
            if ($v === false || $v === '') {
                continue;
            }
            if (count($path) === 2) {
                $config[$path[0]][$path[1]] = $v;
            } else {
                $config[$path[0]] = $v;
            }
        }
        return $config;
    }
}
