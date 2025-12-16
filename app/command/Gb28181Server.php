<?php

namespace app\command;

use Exception;
use ExoSip;
use Gb28181\GateWay\Handlers\GB28181Handler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;


class Gb28181Server extends Command
{
    protected static $defaultName = 'gb28181:server {action}';
    protected static $defaultDescription = '国标信令服务: 启动、停止、重启、查看状态';

    private bool $debug = false;

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, '启动动作：start, stop, or status')
            ->addOption('debug', 'd', InputOption::VALUE_NONE, '是否开启调试模式');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $debug  = $input->getOption('debug');
        if ($debug !== null) {
            $this->debug = $debug;
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

    private function startServer(InputInterface $input, OutputInterface $output): void
    {
        $config = config('gb28181');
        $config['debug'] = $this->debug;
        $sipServer = new ExoSip([
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
        ]);

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
            'redis' => $config['redis'],
            'zlm' => $config['zlm'],
            'api_hock_url' => $config['api']['hock_url'],
            'api_pull_url' => $config['api']['pull_url'],
            'api_hock_token' => $config['api']['token'],
        ]);

        // 绑定GB28181事件处理器
        $gb28181->bindEvents();

        // 打印启动信息
        $output->writeln("=================================");
        $output->writeln("  GB28181  Server");
        $output->writeln("=================================");
        $output->writeln("Server ID: {$config['server_id']}");
        $output->writeln("Domain: {$config['server_domain']}");
        $output->writeln("Listening on: {$config['listen_addr']}:{$config['sip_port']}");
        $output->writeln("Transport: {$config['transport']}");
        $output->writeln("ZLM Media Server IP: {$config['zlm']['media_server_ip']}");
        $output->writeln("=================================\n");
        $output->writeln("[INFO] 服务器已启动，等待设备接入...\n");

        // 启动服务器（阻塞式运行）
        $sipServer->run();
    }

    private function stopServer(InputInterface $input, OutputInterface $output): void
    {
        $pidFile = config('gb28181.pid_file');
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

    private function restartServer(InputInterface $input, OutputInterface $output): void
    {
        $this->stopServer($input, $output);
        $this->startServer($input, $output);
    }

    private function statusServer(InputInterface $input, OutputInterface $output):  void
    {
        // 检查 PID 文件是否存在
        $pidFile = config('gb28181.pid_file');
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
                $output->writeln("错误: 无法获取进程状态\n");
                exit(1);
            }

            // 打印状态信息
            $output->writeln("=============================================\n");
            $output->writeln("  GB28181 Server Status\n");
            $output->writeln("=============================================\n");
            $output->writeln("  PID File: {$pidFile}\n");

            // Master 进程
            if (isset($status['master'])) {
                $master = $status['master'];
                $output->writeln("  [Master Process]\n");
                $output->writeln("    PID:        {$master['pid']}\n");
                $output->writeln("    Status:     {$master['status']}\n");

                if (isset($master['memory_rss_kb'])) {
                    $mem_mb = round($master['memory_rss_kb'] / 1024, 2);
                    $output->writeln("    Memory:     {$mem_mb} MB\n");
                }

                if (isset($master['fd_count'])) {
                    $output->writeln("    FD Count:   {$master['fd_count']}\n");
                }
                $output->writeln("\n");
            }

            // Worker 进程
            if (isset($status['worker'])) {
                $worker = $status['worker'];
                $output->writeln("  [Worker Process]\n");
                $output->writeln("    PID:           {$worker['pid']}\n");
                $output->writeln("    Status:        {$worker['status']}\n");

                if (isset($worker['memory_rss_kb'])) {
                    $mem_mb = round($worker['memory_rss_kb'] / 1024, 2);
                    $output->writeln("    Memory:        {$mem_mb} MB\n");
                }

                if (isset($worker['fd_count'])) {
                    $output->writeln("    FD Count:      {$worker['fd_count']}\n");
                }

                if (isset($worker['uptime'])) {
                    $uptime = $worker['uptime'];
                    $hours = floor($uptime / 3600);
                    $minutes = floor(($uptime % 3600) / 60);
                    $seconds = $uptime % 60;
                    $output->writeln("    Uptime:        {$hours}h {$minutes}m {$seconds}s\n");
                }

                if (isset($worker['restart_count'])) {
                    $output->writeln("    Restart Count: {$worker['restart_count']}\n");
                }
            }

            // Task 进程池
            if (isset($status['tasks']) && is_array($status['tasks'])) {
                $output->writeln("  [Task Worker Pool]\n");
                $output->writeln("    Total: " . count($status['tasks']) . " workers\n");
                $output->writeln("\n");

                foreach ($status['tasks'] as $task) {
                    $taskId = $task['id'];
                    $taskPid = $task['pid'];
                    $taskStatus = $task['status'];

                    $statusIcon = $taskStatus === 'running' ? '✓' : '✗';
                    $memInfo = '';
                    if (isset($task['memory_rss_kb'])) {
                        $mem_mb = round($task['memory_rss_kb'] / 1024, 2);
                        $memInfo = " ({$mem_mb} MB)";
                    }

                    $output->writeln("    Task-{$taskId}: PID {$taskPid} [{$statusIcon} {$taskStatus}]{$memInfo}\n");
                }
            }

            // Long Task 进程池
            if (isset($status['long_tasks']) && is_array($status['long_tasks'])) {
                $output->writeln("  [Long Task Worker Pool]\n");
                $output->writeln("    Total: " . count($status['long_tasks']) . " workers\n");
                foreach ($status['long_tasks'] as $task) {
                    $taskId = $task['id'];
                    $taskPid = $task['pid'];
                    $taskStatus = $task['status'];

                    $statusIcon = $taskStatus === 'running' ? '✓' : '✗';
                    $memInfo = '';
                    if (isset($task['memory_rss_kb'])) {
                        $mem_mb = round($task['memory_rss_kb'] / 1024, 2);
                        $memInfo = " ({$mem_mb} MB)";
                    }

                    $output->writeln("    LongTask-{$taskId}: PID {$taskPid} [{$statusIcon} {$taskStatus}]{$memInfo}\n");
                }
            }

            // 任务统计
            if (isset($status['tasks_posted']) || isset($status['tasks_failed'])) {
                $output->writeln("  [Task Statistics]\n");
                if (isset($status['tasks_posted'])) {
                    $output->writeln("    Posted: {$status['tasks_posted']}\n");
                }

                if (isset($status['tasks_failed'])) {
                    $output->writeln("    Failed: {$status['tasks_failed']}\n");
                }
            }

            $output->writeln("=============================================\n");

            // 返回状态码
            $allRunning = true;
            if (isset($status['master']) && $status['master']['status'] !== 'running') {
                $allRunning = false;
            }
            if (isset($status['worker']) && $status['worker']['status'] !== 'running') {
                $allRunning = false;
            }
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
            $output->writeln("错误: " . $e->getMessage() . "\n");
            exit(1);
        }
    }
}
