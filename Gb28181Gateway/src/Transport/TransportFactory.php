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
     * @param string $type 'redis' 或 'rabbitmq'
     * @param array $config 传输层配置
     * @return TransportInterface
     * @throws \InvalidArgumentException
     */
    public static function create(string $type, array $config): TransportInterface
    {
        return match ($type) {
            'redis' => new RedisTransport($config),
            'rabbitmq' => new RabbitMQTransport($config),
            default => throw new \InvalidArgumentException("Unsupported transport type: {$type}"),
        };
    }
}
