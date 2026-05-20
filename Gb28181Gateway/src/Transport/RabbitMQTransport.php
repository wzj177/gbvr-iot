<?php

namespace Gb28181\GateWay\Transport;

use Gb28181\GateWay\Libs\Logger;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * RabbitMQ 传输实现
 *
 * 使用 php-amqplib 纯 PHP 库，提供 TransportInterface 标准接口
 */
class RabbitMQTransport implements TransportInterface
{
    private ?AMQPStreamConnection $connection = null;
    private ?\PhpAmqpLib\Channel\AMQPChannel $channel = null;
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
            $host = $this->config['host'] ?? '127.0.0.1';
            $port = $this->config['port'] ?? 5672;
            $user = $this->config['user'] ?? 'guest';
            $password = $this->config['password'] ?? 'guest';
            $vhost = $this->config['vhost'] ?? '/';

            $this->connection = new AMQPStreamConnection(
                $host,
                $port,
                $user,
                $password,
                $vhost,
                false,   // insist
                'AMQPLAIN', // login_method
                null,    // login_response
                'en_US', // locale
                3.0,     // connection_timeout
                3.0,     // read_write_timeout
                null,    // context
                false,   // keepalive
                0,       // heartbeat
                10.0     // channel_rpc_timeout
            );

            $this->channel = $this->connection->channel();
            $this->logger->info("[RabbitMQTransport] Connected to {$host}:{$port}", 'Transport');
            return true;
        } catch (\Exception $e) {
            $this->logger->error("[RabbitMQTransport] Connect error: {$e->getMessage()}", 'Transport');
            $this->connection = null;
            $this->channel = null;
            return false;
        }
    }

    public function push(string $queueKey, string $message) : bool
    {
        if (!$this->ensureConnection()) {
            return false;
        }

        try {
            // Declare queue as durable
            $this->channel->queue_declare($queueKey, false, true, false, false);

            $amqpMessage = new AMQPMessage($message, [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            ]);

            $this->channel->basic_publish($amqpMessage, '', $queueKey);
            $this->logger->debug("[RabbitMQTransport] Published message to queue: {$queueKey}", 'Transport');
            return true;
        } catch (\Exception $e) {
            $this->logger->error("[RabbitMQTransport] Push failed: {$e->getMessage()}", 'Transport');
            $this->resetConnection();
            return false;
        }
    }

    public function pop(array $queueKeys, int $timeout) : ?array
    {
        if (!$this->ensureConnection()) {
            return null;
        }

        try {
            // Non-blocking polling with basic_get
            foreach ($queueKeys as $queueKey) {
                $this->channel->queue_declare($queueKey, false, true, false, false);

                $message = $this->channel->basic_get($queueKey);
                if ($message) {
                    $message->ack();
                    $body = $message->body;
                    $this->logger->debug("[RabbitMQTransport] Got message from queue: {$queueKey}", 'Transport');
                    return [$queueKey, $body];
                }
            }

            // No message available, sleep briefly
            if ($timeout > 0) {
                usleep($timeout * 1000000);
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error("[RabbitMQTransport] Pop failed: {$e->getMessage()}", 'Transport');
            $this->resetConnection();
            return null;
        }
    }

    public function isHealthy() : bool
    {
        if (!$this->connection || !$this->channel) {
            return false;
        }

        try {
            return $this->connection->isConnected();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function close() : void
    {
        try {
            if ($this->channel) {
                $this->channel->close();
            }
            if ($this->connection) {
                $this->connection->close();
            }
        } catch (\Exception $e) {
            // Ignore close errors
        }

        $this->channel = null;
        $this->connection = null;
        $this->logger->info("[RabbitMQTransport] Connection closed", 'Transport');
    }

    public function getType() : string
    {
        return 'rabbitmq';
    }

    /**
     * Ensure connection is active, reconnect if needed
     */
    private function ensureConnection() : bool
    {
        if ($this->isHealthy()) {
            return true;
        }

        $this->logger->info("[RabbitMQTransport] Reconnecting...", 'Transport');
        $this->close();
        return $this->connect();
    }

    /**
     * Reset connection after error
     */
    private function resetConnection() : void
    {
        $this->channel = null;
        $this->connection = null;
    }
}
