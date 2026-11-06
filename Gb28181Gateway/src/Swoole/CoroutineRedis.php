<?php

declare(strict_types=1);

namespace Gb28181\GateWay\Swoole;

use Swoole\Coroutine\Socket as CoSocket;

/**
 * 协程化 Redis 客户端（最小 RESP 实现）
 *
 * 为什么不用 phpredis：redis 扩展的阻塞 BLPOP 会挂起整个协程调度器，
 * CommandSubscriber 在协程里跑必须用协程 socket 直连 Redis。
 * 仅实现网关所需命令：AUTH/SELECT/PING/BLPOP/LPUSH/RPUSH。
 */
final class CoroutineRedis
{
    private ?CoSocket $sock = null;

    private string $host = '127.0.0.1';

    private int $port = 6379;

    private ?string $password = null;

    private int $database = 0;

    private ?string $prefix = null;

    private ?string $lastError = null;

    public function __construct(array $config = [])
    {
        $this->host     = (string)($config['host'] ?? '127.0.0.1');
        $this->port     = (int)($config['port'] ?? 6379);
        $this->password = $config['password'] ?? null;
        $this->database = (int)($config['database'] ?? 0);
        $this->prefix   = $config['prefix'] ?? null;
    }

    public function connect(int $timeout = 3) : bool
    {
        $this->lastError = null;
        $sock = new CoSocket(AF_INET, SOCK_STREAM);
        if (!$sock->connect($this->host, $this->port, $timeout)) {
            $this->lastError = "connect {$this->host}:{$this->port} failed: {$sock->errMsg}";
            return false;
        }
        $this->sock = $sock;
        if ($this->password !== null && $this->password !== '') {
            $r = $this->rawCommand('AUTH', (string)$this->password);
            if (!is_string($r) || $r !== 'OK') {
                $this->lastError = 'AUTH failed';
                $this->close();
                return false;
            }
        }
        if ($this->database > 0) {
            $r = $this->rawCommand('SELECT', (string)$this->database);
            if (!is_string($r) || $r !== 'OK') {
                $this->lastError = 'SELECT failed';
                $this->close();
                return false;
            }
        }
        return true;
    }

    public function ping() : bool
    {
        try {
            $r = $this->rawCommand('PING');
            return $r === 'PONG' || $r === '+PONG' || $r === true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /** @return array|null [queueKey, message] 或超时 null */
    public function blPop(array $keys, int $timeout) : ?array
    {
        $args = array_map(fn($k) => $this->applyPrefix($k), $keys);
        $args[] = (string)$timeout;
        $r = $this->rawCommand('BLPOP', ...$args);
        return is_array($r) && count($r) === 2 ? [$this->stripPrefix((string)$r[0]), (string)$r[1]] : null;
    }

    public function rPush(string $key, string $value) : bool
    {
        $r = $this->rawCommand('RPUSH', $this->applyPrefix($key), $value);
        return is_int($r);
    }

    public function lPush(string $key, string $value) : bool
    {
        $r = $this->rawCommand('LPUSH', $this->applyPrefix($key), $value);
        return is_int($r);
    }

    public function close() : void
    {
        $this->sock?->close();
        $this->sock = null;
    }

    public function getLastError() : ?string
    {
        return $this->lastError;
    }

    public function isConnected() : bool
    {
        return $this->sock !== null;
    }

    // ------------------------------------------------------------------

    private function applyPrefix(string $key) : string
    {
        return $this->prefix ? $this->prefix . $key : $key;
    }

    private function stripPrefix(string $key) : string
    {
        if ($this->prefix && str_starts_with($key, $this->prefix)) {
            return substr($key, strlen($this->prefix));
        }
        return $key;
    }

    /** 断线自动重连一次 */
    private function ensureConnected() : void
    {
        if ($this->sock !== null) {
            return;
        }
        if (!$this->connect()) {
            throw new \RuntimeException('redis reconnect failed: ' . $this->lastError);
        }
    }

    /**
     * 执行 RESP 命令
     * @return string|int|bool|array|null
     */
    private function rawCommand(string ...$args)
    {
        $this->ensureConnected();
        $payload = $this->encode($args);
        $n = $this->sock->sendAll($payload);
        if ($n !== strlen($payload)) {
            $this->close();
            throw new \RuntimeException('redis send failed');
        }
        return $this->readReply();
    }

    private function encode(array $args) : string
    {
        $out = '*' . count($args) . "\r\n";
        foreach ($args as $a) {
            $a = (string)$a;
            $out .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
        }
        return $out;
    }

    /**
     * 逐行读取 RESP 应答，$blockedDeadline 用于 BLPOP 超时上限（socket 读超时兜底）
     */
    private function readReply()
    {
        $line = $this->readLine();
        if ($line === null) {
            $this->close();
            throw new \RuntimeException('redis read line failed');
        }
        $type = $line[0];
        $val  = substr($line, 1);
        switch ($type) {
            case '+':
                return $val;
            case '-':
                $this->lastError = $val;
                return null;
            case ':':
                return (int)$val;
            case '$':
                $len = (int)$val;
                if ($len < 0) {
                    return null;   // nil bulk
                }
                $data = $this->readBytes($len + 2);   // 含 \r\n
                return substr($data, 0, $len);
            case '*':
                $count = (int)$val;
                if ($count < 0) {
                    return null;
                }
                $out = [];
                for ($i = 0; $i < $count; $i++) {
                    $out[] = $this->readReply();
                }
                return $out;
        }
        return null;
    }

    private function readLine(?float $timeout = null) : ?string
    {
        $buf    = $this->unread;
        $pos    = strpos($buf, "\r\n");
        if ($pos !== false) {
            $this->unread = substr($buf, $pos + 2);
            return substr($buf, 0, $pos);
        }
        $sock     = $this->sock;
        $deadline = microtime(true) + ($timeout ?? 15.0);
        while (microtime(true) < $deadline) {
            $chunk = $sock->recv(65536, 1.0);
            if ($chunk === false || $chunk === '') {
                $code = $sock->errCode;
                if ($code === 110 || $code === 35 || (defined('SOCKET_ETIMEDOUT') && $code === SOCKET_ETIMEDOUT)) {
                    continue;   // 读超时，继续等到 deadline
                }
                return null;   // 连接错误
            }
            $buf .= $chunk;
            $pos = strpos($buf, "\r\n");
            if ($pos !== false) {
                $this->unread = substr($buf, $pos + 2);
                return substr($buf, 0, $pos);
            }
            if (strlen($buf) > 1024 * 1024) {
                return null;
            }
        }
        return null;
    }

    /** @var string 读行时预读的剩余字节 */
    private string $unread = '';

    private function readBytes(int $len) : string
    {
        $out      = $this->unread;
        $this->unread = '';
        $deadline = microtime(true) + 15.0;
        while (strlen($out) < $len) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('redis read bulk timeout');
            }
            $chunk = $this->sock->recv($len - strlen($out), 2.0);
            if ($chunk === false || $chunk === '') {
                $code = $this->sock->errCode;
                if ($code === 110 || $code === 35 || (defined('SOCKET_ETIMEDOUT') && $code === SOCKET_ETIMEDOUT)) {
                    continue;
                }
                throw new \RuntimeException('redis read bulk failed');
            }
            $out .= $chunk;
        }
        if (strlen($out) > $len) {
            $this->unread = substr($out, $len);
            $out = substr($out, 0, $len);
        }
        return $out;
    }
}
