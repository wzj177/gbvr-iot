<?php

declare(strict_types=1);

namespace Gb28181\GateWay\Swoole;

use Swoole\Process;

/**
 * Master 监督进程（对应 eXosip Master 的 re-fork 行为）
 *
 * worker 子进程崩溃 / 业务内存自保护 exit() 后自动重建（指数退避，上限 10s）；
 * 收到 SIGTERM/SIGINT 优雅停止（转发信号给 worker）。
 * swoole-gbs/server.php 与 onvif-server.php 共用。
 */
final class Supervisor
{
    public function __construct(private $logger = null)
    {
    }

    /**
     * @param string   $name      服务名（日志标识）
     * @param string   $pidFile   PID 文件路径（空则不写）
     * @param callable $worker    worker 主函数（在子进程内执行，协程容器由调用方自行启动）
     * @param bool     $enableCoroutine worker 回调是否运行在协程容器中
     */
    public function run(string $name, string $pidFile, callable $worker, bool $enableCoroutine = true) : void
    {
        $masterPid = getmypid();
        if ($pidFile !== '') {
            @file_put_contents($pidFile, (string)$masterPid);
        }

        $stopping = false;
        pcntl_async_signals(true);
        $onStop = function () use (&$stopping, $name, $masterPid, $pidFile) {
            if ($stopping) {
                return;
            }
            $stopping = true;
            if ($pidFile !== '' && @file_get_contents($pidFile) === (string)$masterPid) {
                @unlink($pidFile);
            }
            $this->log("[{$name}] master stopping (pid={$masterPid})", 'INFO');
        };
        pcntl_signal(SIGTERM, $onStop);
        pcntl_signal(SIGINT, $onStop);

        $restartDelay = 1;
        while (!$stopping) {
            $proc = new Process(function (Process $process) use ($worker, $name) {
                try {
                    $worker($process);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "[{$name} WORKER FATAL] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
                    $process->exit(1);
                }
                $process->exit(0);
            }, false, 0, $enableCoroutine);

            $workerPid = $proc->start();
            $this->log("[{$name}] worker started, pid={$workerPid}", 'INFO');

            while (true) {
                if ($stopping) {
                    posix_kill($workerPid, SIGTERM);
                    // 给 worker 最多 10s 优雅退出，强杀兜底
                    $deadline = microtime(true) + 10;
                    while (microtime(true) < $deadline) {
                        $ret = Process::wait(false);
                        if ($ret !== false) {
                            break;
                        }
                        usleep(200000);
                    }
                    if ($ret === false) {
                        posix_kill($workerPid, SIGKILL);
                        Process::wait(false);
                    }
                    break 2;
                }
                $ret = Process::wait(false);
                if ($ret !== false && ($ret['pid'] ?? 0) === $workerPid) {
                    $this->log("[{$name}] worker exited, code=" . ($ret['code'] ?? 0) . " signal=" . ($ret['signal'] ?? 0), 'INFO');
                    break;
                }
                usleep(200000);
            }

            if ($stopping) {
                break;
            }
            $this->log("[{$name}] worker will restart in {$restartDelay}s...", 'INFO');
            sleep($restartDelay);
            $restartDelay = min($restartDelay * 2, 10);
        }

        $this->log("[{$name}] supervisor stopped", 'INFO');
    }

    private function log(string $msg, string $level = 'INFO') : void
    {
        if ($this->logger !== null && method_exists($this->logger, strtolower($level))) {
            $this->logger->{strtolower($level)}($msg);
            return;
        }
        echo '[' . date('Y-m-d H:i:s') . "] [Supervisor] [{$level}] {$msg}\n";
    }
}
