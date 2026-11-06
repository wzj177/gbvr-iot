<?php

namespace app\command;

use Gb28181\GateWay\Onvif\OnvifGateway;
use Gb28181\GateWay\Swoole\ExoSip;
use Gb28181\GateWay\Swoole\SimpleLogger;
use Gb28181\GateWay\Swoole\Supervisor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * ONVIF 网关服务（Swoole 协程版）
 *
 * 三条流程与 GB 网关同构：
 *   listen（WS-Discovery 239.255.255.250:3702）+
 *   api-to-gateway（onvif:commands 队列）+
 *   gateway-to-api（onvif_* scene hook 上报）
 *
 * 用法：
 *   php webman onvif:server start|stop|restart|status
 */
class OnvifServer extends Command
{
    protected static $defaultName = 'onvif:server {action}';

    protected static $defaultDescription = 'ONVIF网关服务: 设备发现、命令消费、结果上报';

    private string $pidFile = '';

    protected function configure() : void
    {
        $this->addArgument('action', InputArgument::REQUIRED, '启动动作：start, stop, restart, status');
    }

    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $this->pidFile = runtime_path('onvif_server.pid');

        match ($input->getArgument('action')) {
            'start'   => $this->startServer($input, $output),
            'stop'    => $this->stopServer($input, $output),
            'restart' => $this->restartServer($input, $output),
            'status'  => $this->statusServer($input, $output),
            default   => $output->writeln('[ERROR] 无效的启动动作：' . $input->getArgument('action')),
        };
        return self::SUCCESS;
    }

    private function startServer(InputInterface $input, OutputInterface $output) : void
    {
        if (!extension_loaded('swoole')) {
            $output->writeln('<error>[FATAL] 缺少 ext-swoole（需协程 Socket/Channel，4.5+）</error>');
            return;
        }

        $config = config('onvif');
        $envMap = [
            'GB_REDIS_HOST'     => ['redis', 'host'],
            'GB_REDIS_PORT'     => ['redis', 'port'],
            'GB_REDIS_PASSWORD' => ['redis', 'password'],
            'GB_REDIS_DATABASE' => ['redis', 'database'],
            'GB_API_HOCK_URL'   => ['api', 'hock_url'],
            'GB_API_TOKEN'      => ['api', 'token'],
            'ONVIF_BIND_IP'     => ['bind_ip'],
            'ONVIF_DISCOVERY_ENABLED' => ['discovery_enabled'],
            'ONVIF_DEFAULT_USERNAME' => ['default_username'],
            'ONVIF_DEFAULT_PASSWORD' => ['default_password'],
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

        $output->writeln('=================================');
        $output->writeln('  ONVIF Gateway (Swoole Coroutine)');
        $output->writeln('=================================');
        $output->writeln('Swoole: ' . SWOOLE_VERSION);
        $output->writeln('Discovery: ' . (($config['discovery_enabled'] ?? true) ? '239.255.255.250:3702' : 'off'));
        $output->writeln('Queue: ' . ($config['redis']['queue_name'] ?? 'onvif:commands') . ' (priority|normal)');
        $output->writeln("Redis: {$config['redis']['host']}:{$config['redis']['port']} db={$config['redis']['database']}");
        $output->writeln("API Hook: {$config['api']['hock_url']}");
        $output->writeln("PID File: {$this->pidFile}");
        $output->writeln("=================================\n");

        (new Supervisor(new SimpleLogger()))->run(
            'onvif-gateway',
            $this->pidFile,
            function () use ($config) {
                ExoSip::coroutineRun(function () use ($config) {
                    (new OnvifGateway($config, new SimpleLogger()))->start();
                });
            }
        );
    }

    private function stopServer(InputInterface $input, OutputInterface $output) : void
    {
        $pid = $this->readMasterPid($output);
        if ($pid <= 0) {
            return;
        }
        posix_kill($pid, SIGTERM);
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
        $pid = $this->readMasterPid($output);
        if ($pid <= 0) {
            return;
        }
        $output->writeln("ONVIF Gateway Master PID: {$pid}, running: " . (posix_kill($pid, 0) ? 'yes' : 'no'));
    }

    private function readMasterPid(OutputInterface $output, bool $verbose = true) : int
    {
        if (!file_exists($this->pidFile)) {
            if ($verbose) {
                $output->writeln("错误: PID 文件不存在: {$this->pidFile}（服务器可能未运行）");
            }
            return 0;
        }
        return (int)trim((string)file_get_contents($this->pidFile));
    }

    private function readMasterPidSilent() : int
    {
        return is_file($this->pidFile) ? (int)trim((string)file_get_contents($this->pidFile)) : 0;
    }
}
