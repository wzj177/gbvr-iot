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
        $exitCode = $this->process->run(function ($type, $buffer) use(&$setPid) {
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
}
