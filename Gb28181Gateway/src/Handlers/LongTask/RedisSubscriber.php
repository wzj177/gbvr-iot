<?php

namespace Gb28181\GateWay\Handlers\LongTask;

use Gb28181\GateWay\Libs\ClientRedis;
use Gb28181\GateWay\Libs\Logger;

/**
 * Redis 订阅器 - Long Task 专用
 *
 * 职责：
 * 1. 在 Long Task 进程中运行,持续监听 Redis 队列
 * 2. 将收到的消息转发给 Worker 进程处理
 * 3. 管理连接健康状态和优雅退出
 *
 * 使用场景：
 * - GB28181 服务器接收外部命令(通过 Redis)
 * - 异步任务触发(录像查询、回放等)
 */
class RedisSubscriber
{
    private array $config;
    private bool $debug;
    private bool $shouldExit = false;
    private Logger $logger;

    /**
     * 构造函数
     * @param array $config Redis 配置
     * @param bool $debug 是否开启调试模式
     */
    public function __construct(array $config, bool $debug = false)
    {
        $this->config = $config;
        $this->debug = $debug;
        $this->logger = Logger::getInstance();
    }

    /**
     * 运行订阅循环(在 Long Task 进程中调用)
     *
     * @param \ExoSip $server SIP 服务器实例,用于 sendToWorker()
     * @param string $queueKey Redis 队列键名
     * @param int $timeout blPop 超时时间(秒)
     */
    public function run(\ExoSip $server, string $queueKey = 'gb28181:commands', int $timeout = 1): void
    {
        // 设置信号处理器
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, function () {
            $this->logger->info("Received SIGTERM, exiting...", 'RedisSubscriber');
            $this->shouldExit = true;
        });

        $redis = null;
        $lastHeartbeat = 0;
        $heartbeatInterval = 10;

        $this->logger->info("Started (PID: " . getmypid() . ")", 'RedisSubscriber');
        $this->logger->info("Listening on queue: {$queueKey}", 'RedisSubscriber');

        while (!$this->shouldExit) {
            try {
                // 连接 Redis
                if (!$redis) {
                    $redis = $this->connectRedis();
                    if (!$redis) {
                        sleep(1);
                        continue;
                    }
                }

                // 心跳检查
                $now = time();
                if ($now - $lastHeartbeat >= $heartbeatInterval) {
                    if (!$this->checkRedisHealth($redis)) {
                        $redis = null;
                        continue;
                    }
                    $lastHeartbeat = $now;
                }

                // 阻塞等待消息(非阻塞超时,可响应信号)
                $result = $redis->blPop([$queueKey], $timeout);
                if ($this->debug && !empty($result)) {
                    $str = json_encode($result);
                    $this->logger->debug("Received Redis Queue message: {$str}", 'RedisSubscriber');
                }

                if ($result && is_array($result)) {
                    $message = $result[1] ?? '';
                    if ($message) {
                        $this->handleMessage($server, $message);
                    }
                }

                // 处理信号
                pcntl_signal_dispatch();

            } catch (\Exception $e) {
//                $this->logger->error("Error: {$e->getMessage()}", 'RedisSubscriber');
                $redis = null;
                sleep(1);
            }
        }

        $this->logger->info("Exiting gracefully", 'RedisSubscriber');
    }

    /**
     * 连接 Redis
     * @return ClientRedis|null
     */
    private function connectRedis(): ?ClientRedis
    {
        try {
            $redis = new ClientRedis($this->config);
            if (!$redis->connect()) {
                $this->logger->error("Failed to connect Redis", 'RedisSubscriber');
                return null;
            }
            $this->logger->info("Redis connected", 'RedisSubscriber');
            return $redis;
        } catch (\Exception $e) {
            $this->logger->error("Connect error: {$e->getMessage()}", 'RedisSubscriber');
            return null;
        }
    }

    /**
     * 检查 Redis 健康状态
     * @param ClientRedis $redis
     * @return bool
     */
    private function checkRedisHealth(ClientRedis $redis): bool
    {
        try {
            $redis->ping();
//            if ($this->debug) {
//                $this->logger->debug("Redis PING OK", 'RedisSubscriber');
//            }
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Heartbeat failed: {$e->getMessage()}", 'RedisSubscriber');
            return false;
        }
    }

    /**
     * 处理接收到的消息
     * @param \ExoSip $server
     * @param string $message JSON 格式的消息
     */
    private function handleMessage(\ExoSip $server, string $message): void
    {
        $cmd = @json_decode($message, true);
        if (!$cmd) {
            $this->logger->warning("Invalid JSON message", 'RedisSubscriber');
            return;
        }

        $type = $cmd['action'] ?? 'unknown';
        $this->logger->info("Received command: {$type}", 'RedisSubscriber');

        // 转发给 Worker 处理
        if ($server->sendToWorker($cmd)) {
            if ($this->debug) {
                $this->logger->debug("Command forwarded to Worker", 'RedisSubscriber');
            }
        } else {
            $this->logger->error("Failed to forward command to Worker", 'RedisSubscriber');
        }
    }
}