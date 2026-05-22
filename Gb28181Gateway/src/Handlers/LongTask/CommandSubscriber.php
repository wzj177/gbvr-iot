<?php

namespace Gb28181\GateWay\Handlers\LongTask;

use Gb28181\GateWay\Libs\Logger;
use Gb28181\GateWay\Transport\TransportInterface;

/**
 * 命令订阅器 - 替代 RedisSubscriber
 *
 * 支持 TransportInterface 传输层（Redis / RabbitMQ）
 * 在 Long Task 进程中运行，持续监听消息队列
 * 将收到的命令转发给 Worker 进程处理
 */
class CommandSubscriber
{
    private TransportInterface $transport;
    private bool $debug;
    private bool $shouldExit = false;
    private Logger $logger;

    public function __construct(TransportInterface $transport, bool $debug = false)
    {
        $this->transport = $transport;
        $this->debug = $debug;
        $this->logger = Logger::getInstance();
    }

    /**
     * 运行订阅循环（在 Long Task 进程中调用）
     *
     * @param \ExoSip $server SIP 服务器实例，用于 sendToWorker()
     * @param string $queueKey 队列键名
     * @param int $timeout pop 超时时间（秒）
     */
    public function run(\ExoSip $server, string $queueKey = 'gb28181:commands', int $timeout = 1) : void
    {
        // 设置信号处理器
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            $this->logger->info("[CommandSubscriber] Received SIGTERM, exiting...", 'CommandSubscriber');
            $this->shouldExit = true;
        });

        $lastHealthCheck = 0;
        $healthCheckInterval = 10;

        $this->logger->info("[CommandSubscriber] Started (PID: " . getmypid() . ")", 'CommandSubscriber');
        $this->logger->info("[CommandSubscriber] transport={$this->transport->getType()}, queue={$queueKey}", 'CommandSubscriber');

        while (!$this->shouldExit) {
            try {
                // 健康检查（失败时不立即重连，而是下次循环重连）
                $now = time();
                if ($now - $lastHealthCheck >= $healthCheckInterval) {
                    if (!$this->transport->isHealthy()) {
                        $this->logger->error("[CommandSubscriber] Transport unhealthy, will reconnect next loop", 'CommandSubscriber');
                        // transport 内部已设为 null，下次循环会自动重连
                    }
                    $lastHealthCheck = $now;
                }

                // Pop message from queue（transport 内部会自动重连）
                $result = $this->transport->pop([$queueKey], $timeout);

                if ($result && is_array($result)) {
                    $message = $result[1] ?? '';
                    if ($message) {
                        $this->handleMessage($server, $message);
                    }
                }

                // Process signals
                pcntl_signal_dispatch();

            } catch (\Exception $e) {
                $this->logger->error("[CommandSubscriber] Error: {$e->getMessage()}", 'CommandSubscriber');
                // transport 内部已设为 null，sleep 后下次循环会自动重连
                sleep(1);
            }
        }

        $this->transport->close();
        $this->logger->info("[CommandSubscriber] Exiting gracefully", 'CommandSubscriber');
    }

    /**
     * 处理接收到的消息
     */
    private function handleMessage(\ExoSip $server, string $message) : void
    {
        $cmd = @json_decode($message, true);
        if (!$cmd) {
            $this->logger->warning("[CommandSubscriber] Invalid JSON message", 'CommandSubscriber');
            return;
        }

        $action = $cmd['action'] ?? 'unknown';
        $deviceId = $cmd['device_id'] ?? 'unknown';
        $requestId = $cmd['request_id'] ?? 'unknown';

        // 检查指令是否过期
        if (isset($cmd['timestamp'])) {
            $now = time();
            $age = $now - $cmd['timestamp'];

            // 过期阈值：超过 60 秒视为过期
            $maxAge = 60;

            // 允许时钟不同步：未来时间超过 30 秒视为异常
            $maxFuture = 30;

            if ($age > $maxAge) {
                $this->logger->warning("[CommandSubscriber] Command expired, ignoring. action={$action}, device={$deviceId}, age={$age}s, max_age={$maxAge}s", 'CommandSubscriber');
                return;
            }

            if ($age < -$maxFuture) {
                $this->logger->warning("[CommandSubscriber] Command has future timestamp, ignoring. action={$action}, device={$deviceId}, age={$age}s, max_future={$maxFuture}s", 'CommandSubscriber');
                return;
            }
        }

        $this->logger->info("[CommandSubscriber] Received command: {$action}, device={$deviceId}", 'CommandSubscriber');

        // Forward to Worker for processing
        if ($server->sendToWorker($cmd)) {
            if ($this->debug) {
                $this->logger->debug("[CommandSubscriber] Command forwarded to Worker, req_id={$requestId}", 'CommandSubscriber');
            }
        } else {
            $this->logger->error("[CommandSubscriber] Failed to forward command to Worker, req_id={$requestId}", 'CommandSubscriber');
        }
    }
}
