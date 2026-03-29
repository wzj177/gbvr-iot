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
    public function run(\ExoSip $server, string $queueKey = 'gb28181:commands', int $timeout = 1): void
    {
        // 设置信号处理器
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            $this->logger->info("[CommandSubscriber] Received SIGTERM, exiting...", 'CommandSubscriber');
            $this->shouldExit = true;
        });

        $lastHealthCheck = 0;
        $healthCheckInterval = 10;
        $reconnectAttempts = 0;
        $maxReconnectDelay = 30;

        $this->logger->info("[CommandSubscriber] Started (PID: " . getmypid() . ")", 'CommandSubscriber');
        $this->logger->info("[CommandSubscriber] transport={$this->transport->getType()}, queue={$queueKey}", 'CommandSubscriber');

        // Initial connection
        if (!$this->transport->connect()) {
            $this->logger->error("[CommandSubscriber] Initial connection failed", 'CommandSubscriber');
        } else {
            $reconnectAttempts = 0;
        }

        while (!$this->shouldExit) {
            try {
                // Health check
                $now = time();
                if ($now - $lastHealthCheck >= $healthCheckInterval) {
                    if (!$this->transport->isHealthy()) {
                        $this->logger->error("[CommandSubscriber] Transport unhealthy, reconnecting... (attempt " . ($reconnectAttempts + 1) . ")", 'CommandSubscriber');
                        $this->transport->close();

                        $delay = min($reconnectAttempts * 2, $maxReconnectDelay);
                        if ($delay > 0) {
                            sleep($delay);
                        }

                        if ($this->transport->connect()) {
                            $reconnectAttempts = 0;
                            $this->logger->info("[CommandSubscriber] Reconnected successfully", 'CommandSubscriber');
                        } else {
                            $reconnectAttempts++;
                        }
                    }
                    $lastHealthCheck = $now;
                }

                // Pop message from queue
                $result = $this->transport->pop([$queueKey], $timeout);

                if ($result && is_array($result)) {
                    $message = $result[1] ?? '';
                    if ($message) {
                        $this->handleMessage($server, $message);
                    }
                    // Reset reconnect attempts on successful message
                    $reconnectAttempts = 0;
                }

                // Process signals
                pcntl_signal_dispatch();

            } catch (\Exception $e) {
                $this->logger->error("[CommandSubscriber] Error: {$e->getMessage()}", 'CommandSubscriber');
                $reconnectAttempts++;
                sleep(1);
            }
        }

        $this->transport->close();
        $this->logger->info("[CommandSubscriber] Exiting gracefully", 'CommandSubscriber');
    }

    /**
     * 处理接收到的消息
     */
    private function handleMessage(\ExoSip $server, string $message): void
    {
        $cmd = @json_decode($message, true);
        if (!$cmd) {
            $this->logger->warning("[CommandSubscriber] Invalid JSON message", 'CommandSubscriber');
            return;
        }

        $action = $cmd['action'] ?? 'unknown';
        $deviceId = $cmd['device_id'] ?? 'unknown';
        $requestId = $cmd['request_id'] ?? 'unknown';

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
