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
            $this->lock($key, $key, $ex);
            return $fn();
        } finally {
            $this->unlock($key, $key);
        }
    }

    public function tryLock($key, $value = '1', $ex = 6)
    {
        return Redis::set('lock_' . $key, $value, ["NX", "EX" => $ex]);
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
        $script = <<< EOF
if (redis.call("get", "lock_" .. KEYS[1]) == ARGV[1]) then
	return redis.call("del", "lock_" .. KEYS[1])
else
	return 0
end
EOF;
        return Redis::eval($script, [$key, $value], 1) > 0;
    }
}