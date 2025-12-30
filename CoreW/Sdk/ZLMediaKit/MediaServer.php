<?php

namespace CoreW\Sdk\ZLMediaKit;

use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class MediaServer
{
    private ?Process $process = null;

    public function __construct(private readonly array $config)
    {
        $this->validateConfig();
        !is_dir($this->config['log_dir']) && mkdir($this->config['log_dir'], 0755, true);
    }

    private function validateConfig(): void
    {
        foreach (['executable', 'config_file', 'ssl_file', 'log_dir'] as $k) {
            if (!isset($this->config[$k])) {
                throw new \InvalidArgumentException("MediaServer: {$k} is required");
            }
        }
    }

    /**
     * 前台模式启动（推荐 Supervisor）
     */
    public function start(): void
    {
        if ($this->isRunning()) {
            throw new \RuntimeException("ZLMediaKit is already running");
        }

        $command = [
            $this->config['executable'],
            '-c', $this->config['config_file'],
            '-s', $this->config['ssl_file'],
            '--log-dir', $this->config['log_dir'],
        ];

        $this->process = new Process(
            command: $command,
            cwd: dirname($this->config['executable']),
            timeout: null // 不自动 kill
        );

        $this->process->setOptions([
            'create_process_group' => true
        ]);

        $this->bindSignals(); // 支持 Ctrl+C

        // 前台阻塞运行（核心）
        $setPid = false;
        $exitCode = $this->process->run(function ($type, $buffer) use (&$setPid) {
            if (!$setPid) {
                $mediaServerPid = $this->getPid();
                $mediaServerPidFile = $this->config['pid_file'] ?? $this->config['log_dir'] . '/zlm.pid';
                file_put_contents($mediaServerPidFile, $mediaServerPid);
                $setPid = true;
            }
//            $logFile = ($type === Process::ERR)
//                ? $this->config['log_dir'] . '/stderr.log'
//                : $this->config['log_dir'] . '/stdout.log';
            file_put_contents("php://stdout", $buffer);
        });

        $setPid = false;
        if ($exitCode !== 0) {
            throw new ProcessFailedException($this->process);
        }

    }

    /**
     * 捕获 Ctrl+C、SIGTERM 等系统信号，优雅退出
     */
    private function bindSignals(): void
    {
        if (!function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () {
            echo "Received SIGINT, stopping ZLM...\n";
            $this->stop();
        });

        pcntl_signal(SIGTERM, function () {
            echo "Received SIGTERM, stopping ZLM...\n";
            $this->stop();
        });

        pcntl_signal(SIGHUP, function () {
            echo "Received SIGHUP, stopping ZLM...\n";
            if ($this->process?->getPid()) {
                posix_kill(-$this->process->getPid(), SIGTERM); // kill group
            }
        });
    }

    /**
     * 优雅停止
     */
    public function stop(): void
    {
        if (!$this->isRunning()) {
            return;
        }

        // 优雅退出（给 ZLM 机会清理）
        $this->process->signal(SIGTERM);
        sleep(1);
        if ($this->process->isRunning()) {
            echo "Force killing ZLM...\n";
            $this->process->signal(SIGKILL);
        }

        $mediaServerPidFile = $this->config['pid_file'] ?? $this->config['log_dir'] . '/zlm.pid';
        file_exists($mediaServerPidFile) && @unlink($mediaServerPidFile);
    }

    public function isRunning(): bool
    {
        return $this->process && $this->process->isRunning();
    }

    public function getPid(): ?int
    {
        return $this->process?->getPid();
    }

    public function stopWithPidFile(): void
    {
        $mediaServerPidFile =
            $this->config['pid_file']
            ?? (($this->config['log_dir'] ?? null) ? rtrim($this->config['log_dir'], '/') . '/zlm.pid' : null);

        if (!$mediaServerPidFile || !is_file($mediaServerPidFile)) {
            return;
        }

        $pid = (int)trim(file_get_contents($mediaServerPidFile));
        if ($pid <= 0) {
            return;
        }

        // 先尝试向进程组发送 SIGTERM（使用负 PID 表示进程组）
        // 但也要尝试直接向 PID 发送信号，以防进程组方式不起作用
        $processKilled = false;
        
        // 尝试向进程组发送信号
        if (@posix_kill(-$pid, SIGTERM)) {
            $processKilled = true;
        }
        
        // 等待最多 3 秒，检查进程是否已终止
        $waitMs = 3000;
        $intervalMs = 100;
        for ($elapsed = 0; $elapsed < $waitMs; $elapsed += $intervalMs) {
            if (!@posix_kill($pid, 0) && !@posix_kill(-$pid, 0)) {
                // 进程和进程组都不存在，删除 pid 文件
                @unlink($mediaServerPidFile);
                return;
            }
            usleep($intervalMs * 1000);
        }

        // 还活着，尝试向进程组发送 SIGKILL
        if (@posix_kill(-$pid, SIGKILL)) {
            $processKilled = true;
        }
        
        // 再次等待，确认进程被终止
        $waitMs = 1000; // 额外等待1秒
        $intervalMs = 100;
        for ($elapsed = 0; $elapsed < $waitMs; $elapsed += $intervalMs) {
            if (!@posix_kill($pid, 0) && !@posix_kill(-$pid, 0)) {
                // 进程和进程组都不存在，删除 pid 文件
                @unlink($mediaServerPidFile);
                return;
            }
            usleep($intervalMs * 1000);
        }

        // 如果还是没有杀死进程，尝试杀死进程组中的所有子进程
        // 使用 ps 命令查找进程组中的所有子进程
        $this->killProcessTree($pid);

        // 清理 pid 文件（防止僵尸 pid）
        @unlink($mediaServerPidFile);
    }
    
    /**
     * 杀死进程树，包括所有子进程
     */
    private function killProcessTree(int $pid): void
    {
        // 尝试使用 pgrep 查找进程组的所有子进程
        $command = "pgrep -P {$pid} 2>/dev/null";
        $output = [];
        $returnCode = 0;
        @exec($command, $output, $returnCode);
        
        if ($returnCode === 0 && !empty($output)) {
            foreach ($output as $childPid) {
                if (is_numeric($childPid)) {
                    $this->killProcessTree((int)$childPid); // 递归终止子进程
                }
            }
        }
        
        // 最后再尝试终止主进程
        @posix_kill($pid, SIGKILL);
    }
}
