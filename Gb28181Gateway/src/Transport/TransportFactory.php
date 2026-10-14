<?php

namespace Gb28181\GateWay\Transport;

/**
 * 传输层工厂
 */
class TransportFactory
{
    /**
     * 创建传输层实例
     *
     * @param string $type 'redis'（phpredis，阻塞，exosip 版用）、'redis_co'（协程化，Swoole 版用）或 'rabbitmq'
     * @param array $config 传输层配置
     * @return TransportInterface
     * @throws \InvalidArgumentException
     */
    public static function create(string $type, array $config) : TransportInterface
    {
        return match ($type) {
            'redis' => new RedisTransport($config),
            'redis_co' => new \Gb28181\GateWay\Swoole\CoroutineRedisTransport($config),
            'rabbitmq' => new RabbitMQTransport($config),
            default => throw new \InvalidArgumentException("Unsupported transport type: {$type}"),
        };
    }
}
