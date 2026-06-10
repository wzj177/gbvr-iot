<?php

namespace CoreW\Business\Lock;


use support\Redis;

class RedisLock implements LockInterface
{
    /**
     * @param $key
     * @param $fn
     * @param int|null $ex
     * @return mixed
     */
    public function exec($key, $fn, ?int $ex = 6)
    {
        try {
            $this->lock($key, '1', $ex);
            return $fn();
        } finally {
            $this->unlock($key, $key);
        }
    }

    /**
     * 尝试获取锁并执行，获取失败立即返回 null（不自旋等待）
     *
     * 适用场景：start 发现 stop 正在持锁 → 直接返回"稍后重试"，避免阻塞 7-8 秒
     *
     * @param string $key 锁键名
     * @param callable $fn 获取锁后执行的回调
     * @param int $ex 锁超时秒数
     * @return mixed|null 成功返回 $fn() 的结果，锁被占返回 null
     */
    public function tryExec (string $key, callable $fn, int $ex = 10) : mixed
    {
        if (!$this->tryLock('lock_' . $key, '1', $ex)) {
            return null;
        }
        try {
            return $fn();
        } finally {
            $this->unlock($key, $key);
        }
    }

    public function tryLock($key, $value = '1', $ttl = 6)
    {
        $redis = Redis::connection()->client();

        return $redis->set($key, $value, ['nx', 'ex' => $ttl]);
    }

    public function lock($key, $value = '1', $ex = 6)
    {
        if ($this->tryLock($key, $value, $ex)) {
            return true;
        }
        usleep(200);
        $this->lock($key, $value, $ex);
    }

    public function unlock($key, $value = '1')
    {
        $script = <<<LUA
if redis.call("get", "lock_" .. KEYS[1]) == ARGV[1] then
    return redis.call("del", "lock_" .. KEYS[1])
else
    return 0
end
LUA;

        return Redis::eval($script, 1, $key, $value);
    }
}