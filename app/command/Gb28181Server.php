<?php

namespace app\command;

use Exception;
use ExoSip;
use Gb28181\GateWay\Handlers\GB28181Handler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class Gb28181Server extends Command
{
    protected static $defaultName = 'gb28181:server {action}';
    protected static $defaultDescription = '国标信令服务: 启动、停止、重启、查看状态';

    private bool $debug = false;

    private string $mode = 'UDP';

    /**
     * @return void
     */
    protected function configure() : void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, '启动动作：start, stop, or status')
            ->addOption('tcp', 't', InputOption::VALUE_NONE, '以TCP方式启动')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, '是否开启调试模式');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output) : int
    {
        $action = $input->getArgument('action');
        $debug = $input->getOption('debug');
        $tcp = $input->getOption('tcp');
        $this->debug = $debug;
        if ($tcp) {
            $this->mode = 'TCP';
        }

        match ($action) {
            'start' => $this->startServer($input, $output),
            'stop' => $this->stopServer($input, $output),
            'restart' => $this->restartServer($input, $output),
            'status' => $this->statusServer($input, $output),
            default => $output->writeln("[ERROR] 无效的启动动作：{$action}"),
        };
        return self::SUCCESS;
    }

    private function startServer(InputInterface $input, OutputInterface $output) : void
    {
        $config = $this->mode === 'UDP' ? config('gb28181') : config('gb28181_tcp');
        $config['debug'] = $this->debug;

        $sipOptions = [
            'ua'                   => $config['user_agent'],
            'ip'                   => $config['listen_addr'],
            'port'                 => $config['sip_port'],
            'mode'                 => $config['transport'],
            'debug'                => $config['debug'],
            'task_worker_num'      => $config['task_worker_num'],
            'long_task_worker_num' => 2,
            'pid_file'             => $config['pid_file'],
            'sipId'                => $config['server_id'],
            'sipRealm'             => $config['server_domain'],
            'timer_interval'       => $config['timer_interval'],
        ];

        if (!empty($config['public_ip'])) {
            $sipOptions['public_ip'] = $config['public_ip'];
        }

        $sipServer = new ExoSip($sipOptions);

        // 直接使用 config/gb28181.php，只补充 Handler 需要的别名 key
        $handlerConfig = $config;
        $handlerConfig['check_interval'] = $config['timer_interval'];
        $handlerConfig['api_hock_url'] = $config['api']['hock_url'];
        $handlerConfig['api_pull_url'] = $config['api']['pull_url'];
        $handlerConfig['api_hock_token'] = $config['api']['token'];
        $handlerConfig['mq_type'] = $config['mq_type'] ?? 'redis';
        $handlerConfig['sip_host'] = $config['listen_addr'];
        // RabbitMQ 配置展开到 mq_config
        if (($handlerConfig['mq_type'] === 'rabbitmq') && !empty($config['rabbitmq'])) {
            $handlerConfig['mq_config'] = $config['rabbitmq'];
        }

        // 创建GB28181事件处理器
        $gb28181 = new GB28181Handler($sipServer, $handlerConfig);

        // 绑定GB28181事件处理器
        $gb28181->bindEvents();

        // 打印启动信息
        $output->writeln("=================================");
        $output->writeln("  GB28181 Server");
        $output->writeln("=================================");
        $output->writeln("Server ID: {$config['server_id']}");
        $output->writeln("Domain: {$config['server_domain']}");
        $output->writeln("Listening on: {$config['listen_addr']}:{$config['sip_port']}");
        $output->writeln("Transport: {$config['transport']}");
        if (!empty($config['gateway_id'])) {
            $output->writeln("Gateway ID: {$config['gateway_id']}");
            $output->writeln("MQ Type: {$handlerConfig['mq_type']}");
        }
        $output->writeln("Log file: {$config['log_file']}");
        $output->writeln("Log level: {$config['log_level']}");
        $output->writeln("=================================\n");
        $output->writeln("[INFO] 服务器已启动，等待设备接入...\n");

        // 启动服务器（阻塞式运行）
        $sipServer->run();
    }

    private function stopServer(InputInterface $input, OutputInterface $output) : void
    {
        $pidFile = $this->mode === 'UDP' ? config('gb28181.pid_file') : config('gb28181_tcp.pid_file');
        if (!file_exists($pidFile)) {
            $output->writeln("错误: PID 文件不存在: {$pidFile}\n");
            $output->writeln("提示: 服务器可能未运行，或 PID 文件路径不正确\n");
            exit(1);
        }

        // 读取 PID
        $masterPid = (int)trim(file_get_contents($pidFile));
        if ($masterPid <= 0) {
            $output->writeln("[ERROR] 无效的 PID: {$masterPid}");
            exit(1);
        }

        if (!posix_kill($masterPid, 0)) {
            $output->writeln("[ERROR] 无效的 PID: {$masterPid}");
            $output->writeln("提示: 停止失败，请检查 PID 是否正确");
            exit(1);
        }

        if (!posix_kill($masterPid, SIGTERM)) {
            $output->writeln("[ERROR] 无法停止服务器: {$masterPid}");
            $output->writeln("提示: 请检查 PID 是否正确");
            exit(1);
        }

        $output->writeln("[INFO] 服务器已停止");

        exit(0);
    }

    private function restartServer(InputInterface $input, OutputInterface $output) : void
    {
        $this->stopServer($input, $output);
        $this->startServer($input, $output);
    }

    private function statusServer(InputInterface $input, OutputInterface $output) : void
    {
        // 检查 PID 文件是否存在
        $pidFile = $this->mode === 'UDP' ? config('gb28181.pid_file') : config('gb28181_tcp.pid_file');
        if (!file_exists($pidFile)) {
            $output->writeln("错误: PID 文件不存在: {$pidFile}\n");
            $output->writeln("提示: 服务器可能未运行，或 PID 文件路径不正确\n");
            exit(1);
        }

        // 读取 PID
        $masterPid = (int)trim(file_get_contents($pidFile));
        if ($masterPid <= 0) {
            $output->writeln("[ERROR] 无效的 PID: {$masterPid}");
            exit(1);
        }

        // 检查 Master 进程是否存在
        if (!posix_kill($masterPid, 0)) {
            $output->writeln("[ERROR] 无效的 PID: {$masterPid}");
            $output->writeln("提示: 服务器可能已停止，但 PID 文件未删除");
            exit(1);
        }

        // 获取进程状态
        try {
            $status = ExoSip::getRunStatus($pidFile);

            if (!$status) {
                $output->writeln("<error>错误: 无法获取进程状态</error>");
                exit(1);
            }

            $output->writeln("=============================================");
            $output->writeln("  GB28181 Server Status");
            $output->writeln("=============================================");
            $output->writeln("  PID File: {$pidFile}");
            $output->writeln("");

            // Master Process
            if (isset($status['master'])) {
                $master = $status['master'];
                $table = new Table($output);
                $table->setHeaderTitle('[Master Process]')
                    ->setHeaders(['PID', 'Status', 'Memory', 'FD Count'])
                    ->setRows([
                        [
                            $master['pid'] ?? '',
                            $master['status'] ?? '',
                            isset($master['memory_rss_kb']) ? round($master['memory_rss_kb'] / 1024, 2) . ' MB' : '',
                            $master['fd_count'] ?? '',
                        ],
                    ]);
                $table->render();
                $output->writeln("");
            }

            // Worker Process
            if (isset($status['worker'])) {
                $worker = $status['worker'];
                $table = new Table($output);
                $table->setHeaderTitle('[Worker Process]')
                    ->setHeaders(['PID', 'Status', 'Memory', 'FD Count', 'Uptime', 'Restart Count'])
                    ->setRows([
                        [
                            $worker['pid'] ?? '',
                            $worker['status'] ?? '',
                            isset($worker['memory_rss_kb']) ? round($worker['memory_rss_kb'] / 1024, 2) . ' MB' : '',
                            $worker['fd_count'] ?? '',
                            isset($worker['uptime']) ? sprintf("%dh %dm %ds",
                                floor($worker['uptime'] / 3600),
                                floor(($worker['uptime'] % 3600) / 60),
                                $worker['uptime'] % 60) : '',
                            $worker['restart_count'] ?? '',
                        ],
                    ]);
                $table->render();
                $output->writeln("");
            }

            // Task Worker Pool
            if (isset($status['tasks']) && is_array($status['tasks'])) {
                $table = new Table($output);
                $table->setHeaderTitle('[Task Worker Pool]')
                    ->setHeaders(['Task ID', 'PID', 'Status', 'Memory']);
                foreach ($status['tasks'] as $task) {
                    $mem = isset($task['memory_rss_kb']) ? round($task['memory_rss_kb'] / 1024, 2) . ' MB' : '';
                    $statusIcon = $task['status'] === 'running' ? '✓' : '✗';
                    $table->addRow([$task['id'], $task['pid'], "{$statusIcon} {$task['status']}", $mem]);
                }
                $table->render();
                $output->writeln("");
            }

            // Long Task Worker Pool
            if (isset($status['long_tasks']) && is_array($status['long_tasks'])) {
                $table = new Table($output);
                $table->setHeaderTitle('[Long Task Worker Pool]')
                    ->setHeaders(['Task ID', 'PID', 'Status', 'Memory']);
                foreach ($status['long_tasks'] as $task) {
                    $mem = isset($task['memory_rss_kb']) ? round($task['memory_rss_kb'] / 1024, 2) . ' MB' : '';
                    $statusIcon = $task['status'] === 'running' ? '✓' : '✗';
                    $table->addRow([$task['id'], $task['pid'], "{$statusIcon} {$task['status']}", $mem]);
                }
                $table->render();
                $output->writeln("");
            }

            // Task Statistics
            if (isset($status['tasks_posted']) || isset($status['tasks_failed'])) {
                $table = new Table($output);
                $table->setHeaderTitle('[Task Statistics]')
                    ->setHeaders(['Posted', 'Failed'])
                    ->setRows([
                        [
                            $status['tasks_posted'] ?? 0,
                            $status['tasks_failed'] ?? 0,
                        ],
                    ]);
                $table->render();
                $output->writeln("");
            }

            // 返回状态码
            $allRunning = true;
            if (isset($status['master']) && $status['master']['status'] !== 'running') $allRunning = false;
            if (isset($status['worker']) && $status['worker']['status'] !== 'running') $allRunning = false;
            if (isset($status['tasks'])) {
                foreach ($status['tasks'] as $task) {
                    if ($task['status'] !== 'running') {
                        $allRunning = false;
                        break;
                    }
                }
            }

            exit($allRunning ? 0 : 1);

        } catch (Exception $e) {
            $output->writeln("<error>错误: {$e->getMessage()}</error>");
            exit(1);
        }
    }
}
