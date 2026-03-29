<?php

namespace Gb28181\GateWay\Transport;

/**
 * 传输层抽象接口
 *
 * 支持 Redis 和 RabbitMQ 两种消息队列传输方式
 */
interface TransportInterface
{
    /**
     * 建立连接
     * @return bool
     */
    public function connect(): bool;

    /**
     * 推送消息到队列
     * @param string $queueKey 队列键名
     * @param string $message 消息内容（JSON）
     * @return bool
     */
    public function push(string $queueKey, string $message): bool;

    /**
     * 从队列中弹出消息（阻塞或非阻塞）
     * @param array $queueKeys 队列键名列表
     * @param int $timeout 超时时间（秒），0为非阻塞
     * @return array|null [queueKey, message] 或 null
     */
    public function pop(array $queueKeys, int $timeout): ?array;

    /**
     * 检查连接是否健康
     * @return bool
     */
    public function isHealthy(): bool;

    /**
     * 关闭连接
     */
    public function close(): void;

    /**
     * 获取传输类型
     * @return string 'redis' 或 'rabbitmq'
     */
    public function getType(): string;
}
