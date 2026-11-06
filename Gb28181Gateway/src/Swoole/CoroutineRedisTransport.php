<?php

declare(strict_types=1);

namespace Gb28181\GateWay\Swoole;

use Gb28181\GateWay\Transport\TransportInterface;

/**
 * 协程化 Redis 传输层（Swoole 版网关专用）
 *
 * 与 RedisTransport（phpredis 阻塞版）接口一致、配置结构一致（$config['redis'] 直接复用），
 * 区别：BLPOP 走协程 socket，只挂起当前协程、不阻塞事件循环。
 * TransportFactory::create('redis_co', $config) 创建。
 */
final class CoroutineRedisTransport implements TransportInterface
{
    private CoroutineRedis $redis;

    private bool $debug;

    public function __construct(array $config = [], bool $debug = false)
    {
        $this->redis = new CoroutineRedis($config);
        $this->debug = $debug;
    }

    public function connect() : bool
    {
        return $this->redis->connect();
    }

    public function push(string $queueKey, string $message) : bool
    {
        try {
            return $this->redis->rPush($queueKey, $message);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function pop(array $queueKeys, int $timeout) : ?array
    {
        try {
            return $this->redis->blPop($queueKeys, $timeout);
        } catch (\Throwable $e) {
            // 断线：关闭待重连（ensureConnected 会在下次命令时自动重连）
            $this->redis->close();
            return null;
        }
    }

    public function isHealthy() : bool
    {
        return $this->redis->ping();
    }

    public function close() : void
    {
        $this->redis->close();
    }

    public function getType() : string
    {
        return 'redis';
    }
}
