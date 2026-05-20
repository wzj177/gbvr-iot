<?php

namespace Gb28181\GateWay\Transport;

use Gb28181\GateWay\Libs\ClientRedis;
use Gb28181\GateWay\Libs\Logger;

/**
 * Redis 传输实现
 *
 * 封装 ClientRedis，提供 TransportInterface 标准接口
 */
class RedisTransport implements TransportInterface
{
    private ?ClientRedis $redis = null;
    private array $config;
    private Logger $logger;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->logger = Logger::getInstance();
    }

    public function connect() : bool
    {
        try {
            $this->redis = new ClientRedis($this->config);
            if (!$this->redis->connect()) {
                $this->logger->error("[RedisTransport] Failed to connect", 'Transport');
                return false;
            }
            $this->logger->info("[RedisTransport] Connected successfully", 'Transport');
            return true;
        } catch (\Exception $e) {
            $this->logger->error("[RedisTransport] Connect error: {$e->getMessage()}", 'Transport');
            return false;
        }
    }

    public function push(string $queueKey, string $message) : bool
    {
        // Auto-reconnect if not connected
        if (!$this->redis) {
            if (!$this->connect()) {
                return false;
            }
        }

        try {
            $result = $this->redis->lPush($queueKey, $message);
            $this->logger->debug("[RedisTransport] Pushed message to queue: {$queueKey}", 'Transport');
            return $result !== false;
        } catch (\Exception $e) {
            $this->logger->error("[RedisTransport] Push failed: {$e->getMessage()}, reconnecting...", 'Transport');
            // Connection lost, force reconnect on next operation
            $this->redis = null;
            return false;
        }
    }

    public function pop(array $queueKeys, int $timeout) : ?array
    {
        // Auto-reconnect if not connected
        if (!$this->redis) {
            if (!$this->connect()) {
                return null;
            }
        }

        try {
            $result = $this->redis->blPop($queueKeys, $timeout);
            if ($result && is_array($result)) {
                return $result;
            }
            return null;
        } catch (\Exception $e) {
            $this->logger->error("[RedisTransport] Pop failed: {$e->getMessage()}, reconnecting...", 'Transport');
            // Connection lost, force reconnect on next pop
            $this->redis = null;
            return null;
        }
    }

    public function isHealthy() : bool
    {
        if (!$this->redis) {
            return false;
        }

        try {
            $result = $this->redis->ping();
            if (!$result) {
                // ping returned false, connection is dead
                $this->redis = null;
            }
            return $result;
        } catch (\Exception $e) {
            $this->logger->error("[RedisTransport] Health check failed: {$e->getMessage()}", 'Transport');
            // Connection lost, force reconnect on next operation
            $this->redis = null;
            return false;
        }
    }

    public function close() : void
    {
        $this->redis = null;
        $this->logger->info("[RedisTransport] Connection closed", 'Transport');
    }

    public function getType() : string
    {
        return 'redis';
    }
}
