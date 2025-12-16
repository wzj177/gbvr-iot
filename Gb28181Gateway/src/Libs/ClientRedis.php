<?php

namespace Gb28181\GateWay\Libs;

use \Redis;
class ClientRedis
{
    private Redis $redis;

    private string $host;
    private ?string $password = null;

    private int $port = 6379;

    private int $database = 0;

    private ?string $prefix = null;

    public function __construct(array $config)
    {
        if (!extension_loaded('redis')) {
            throw new \Exception('Redis extension not loaded');
        }
//        'host' => '127.0.0.1',
//        'password' => null,
//        'port' => 6379,
//        'database' => 11,
//        'prefix' => 'gbvr_iot_sip_gateway_'
        $this->host = $config['host'] ?? '127.0.0.1';
        $this->password = $config['password'] ?? null;
        $this->port = $config['port'] ?? 6379;
        $this->database = $config['database'] ?? 0;
        $this->prefix = $config['prefix'] ?? null;
        $this->redis = new Redis();
    }

    public function connect(): bool
    {
        try {
            $this->redis->connect($this->host, $this->port);
            if ($this->password) {
                $this->redis->auth($this->password);
            }
            $this->redis->select($this->database);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }


    public function pconnect()
    {
        try {
            $this->redis->pconnect($this->host, $this->port);
            if ($this->password) {
                $this->redis->auth($this->password);
            }
            $this->redis->select($this->database);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // ping
    public function ping(): bool
    {
        return $this->redis->ping() === '+PONG' || $this->redis->ping() === true;
    }

    // blPop
    public function blPop(array $keys, int $timeout): ?array
    {
        $keys = array_map(function ($key) {
            return $this->prefix ? $this->prefix . $key : $key;
        }, $keys);
        return $this->redis->blPop($keys, $timeout);
    }

    public function get(string $key): ?string
    {
        return $this->redis->get($this->prefix  ? $this->prefix . $key : $key);
    }

    public function set(string $key, string $value, int $expire = 0): bool
    {
        $prefixedKey = $this->prefix ? $this->prefix . $key : $key;
        if ($expire > 0) {
            return $this->redis->setex($prefixedKey, $expire, $value);
        }
        return $this->redis->set($prefixedKey, $value);
    }

    public function del(string $key): int
    {
        return $this->redis->del($this->prefix ? $this->prefix . $key : $key);
    }

    public function exists(string $key): bool
    {
        return $this->redis->exists($this->prefix ? $this->prefix . $key : $key);
    }

    public function expire(string $key, int $seconds): bool
    {
        return $this->redis->expire($this->prefix ? $this->prefix . $key : $key, $seconds);
    }

    public function hSet(string $key, string $field, string $value): int
    {
        return $this->redis->hSet($this->prefix ? $this->prefix . $key : $key, $field, $value);
    }

    public function hGet(string $key, string $field): ?string
    {
        $result = $this->redis->hGet($this->prefix ? $this->prefix . $key : $key, $field);
        return $result !== false ? $result : null;
    }

    public function hDel(string $key, string $field): int
    {
        return $this->redis->hDel($this->prefix ? $this->prefix . $key : $key, $field);
    }

    public function hExists(string $key, string $field): bool
    {
        return $this->redis->hExists($this->prefix ? $this->prefix . $key : $key, $field);
    }

    public function hGetAll(string $key): array
    {
        return $this->redis->hGetAll($this->prefix ? $this->prefix . $key : $key) ?: [];
    }

    public function incr(string $key, int $by = 1): int
    {
        $prefixedKey = $this->prefix ? $this->prefix . $key : $key;
        if ($by === 1) {
            return $this->redis->incr($prefixedKey);
        }
        return $this->redis->incrBy($prefixedKey, $by);
    }

    public function decr(string $key, int $by = 1): int
    {
        $prefixedKey = $this->prefix ? $this->prefix . $key : $key;
        if ($by === 1) {
            return $this->redis->decr($prefixedKey);
        }
        return $this->redis->decrBy($prefixedKey, $by);
    }

    public function lPush(string $key, string $value): int
    {
        return $this->redis->lPush($this->prefix ? $this->prefix . $key : $key, $value);
    }

    public function rPush(string $key, string $value): int
    {
        return $this->redis->rPush($this->prefix ? $this->prefix . $key : $key, $value);
    }

    public function lPop(string $key): ?string
    {
        $result = $this->redis->lPop($this->prefix ? $this->prefix . $key : $key);
        return $result !== false ? $result : null;
    }

    public function rPop(string $key): ?string
    {
        $result = $this->redis->rPop($this->prefix ? $this->prefix . $key : $key);
        return $result !== false ? $result : null;
    }

    public function lLen(string $key): int
    {
        return $this->redis->lLen($this->prefix ? $this->prefix . $key : $key);
    }

    public function publish(string $channel, string $message): int
    {
        return $this->redis->publish($this->prefix ? $this->prefix . $channel : $channel, $message);
    }

    public function subscribe(string $channel, callable $callback): void
    {
        $this->redis->subscribe([$this->prefix ? $this->prefix . $channel : $channel], $callback);
    }
    
    public function checkHealth(): bool
    {
        try {
            return $this->redis->ping() === '+PONG' || $this->redis->ping() === true;
        } catch (\Exception $e) {
            return false;
        }
    }
}