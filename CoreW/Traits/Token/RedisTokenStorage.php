<?php


namespace CoreW\Traits\Token;


use CoreW\Business\User\Exception\UserException;
use support\Redis;
use support\utils\ArrayToolkit;

trait RedisTokenStorage
{
    // 获取锁的超时时间，单位为秒
    protected $lockTimeout = 10;
    // 重试获取锁的时间间隔，单位为毫秒
    protected $lockRetryInterval = 100;
    
    protected $hashTableName;
    
    protected $countKey;
    
    public function setHashTableName($name)
    {
        $this->hashTableName = $name;
    }
    
    public function getHashTableName()
    {
       return empty($this->hashTableName)  ? self::HASH_TABLE_NAME : $this->hashTableName;
    }

    /**
     * @param int $lockTimeout
     */
    public function setLockTimeout(int $lockTimeout): void
    {
        $this->lockTimeout = $lockTimeout;
    }

    /**
     * @param int $lockRetryInterval
     */
    public function setLockRetryInterval(int $lockRetryInterval): void
    {
        $this->lockRetryInterval = $lockRetryInterval;
    }

    public function get($id, array $options = array())
    {
        $key = $this->getHashTableName() . ":" . $id;
        // 获取哈希表值
        $token = $this->getRedis()->hgetall($key);

        return $token ? $this->unSerialize($token) : null;
    }

    public function getByToken(string $token)
    {
        $items = $this->getAllTokens();
        $tokens = array_values(array_filter($items, function ($val) use ($token) {
            return $val['token'] === $token;
        }));
        return count($tokens) ? $tokens[0] : null;
    }

    public function create(array $token)
    {
        // 生成新的自增 ID
        if (is_object($token['token'])) {
            $token['token'] = $token['token']->toString();
        }
        // 获取锁
        $lockKey = $this->getHashTableName() . ':lock';
        $acquiredLock = false;
        $startTime = microtime(true);
        $timeout = $token['lock_timeout'] ?? $this->lockTimeout;
        $retryInterval = $token['lock_retry_timeout'] ?? $this->lockRetryInterval;
        //通过循环不断尝试获取锁，直到获取到锁或超时。如果获取到锁，则将数据保存到 Redis 中，并在保存后删除锁。如果保存失败或获取锁失败，则同样需要删除锁
        while (microtime(true) - $startTime < $timeout) {
            if ($this->getRedis()->setnx($lockKey, 1)) {
                $this->getRedis()->expire($lockKey, $timeout);
                $acquiredLock = true;
                break;
            }
            usleep($retryInterval * 1000);
        }

        if (!$acquiredLock) {
            throw UserException::CREATE_TOKEN_WITH_REDIS_ACQUIRED_LOCK_FAILED();
        }

        $id = $this->getRedis()->incr(self::COUNT_KEY);
        $token['id'] = $id;
        // 将数组保存到 Redis 的哈希表中，并设置缓存过期时间
        $tokenKey = $this->getHashTableName() . ':' . $id;
        if (is_array($token['data'])) {
            $token['data'] = serialize($token['data']);
        }
        $result = $this->getRedis()->hMSet($tokenKey, $token);
        if ($result) {
            $this->getRedis()->expire($tokenKey, $token['expiredTime']);
            $this->getRedis()->sAdd(self::HASH_TABLE_NAME, $tokenKey);
            //锁就会被立即删除，避免了锁过期导致的并发问题。
            $this->getRedis()->del($lockKey);

            return $token;
        }

        $this->getRedis()->del($lockKey);

        return null;
    }

    public function deleteByToken(array $token)
    {
        if (!empty($token['id'])) {
            $this->delete($token['id']);
            return true;
        }

        return false;
    }

    public function wave(array $ids, array $diffs)
    {
        foreach ($ids as $key => $id) {
            $diff = $diffs[$key];
            $token = $this->get($id);
            if (empty($token)) {
                continue;
            }

            foreach ($diff as $dKey => $dVal) {
                if (isset($token[$dKey])) {
                    $token[$dKey] = intval($token[$dKey]) + $dVal;
                    $this->update($id, $token);
                }
            }
        }
    }

    public function update($id, $token)
    {
        $key = $this->getHashTableName() . ":" . $id;
        // 将哈希表值存入 Redis 中
        $result = $this->getRedis()->hMSet($key, $token);

        return $result;
    }

    public function delete($id)
    {
        $key = $this->getHashTableName() . ":" . $id;
        // 获取哈希表值
        $this->getRedis()->del($key);
        $this->getRedis()->sRem(self::HASH_TABLE_NAME, $key);
    }

    public function findByUserIdAndType($userId, $type)
    {
        $items = $this->getAllTokens();
        $values = [];
        foreach ($items as $item) {
            if ($item['userId'] == $userId && $item['type'] == $type) {
                $values[] = $this->unSerialize($item);
            }
        }

        return $values;
    }

    public function destroyTokensByUserId($userId)
    {
        $items = $this->getAllTokens();
        foreach ($items as $item) {
            if ($item['userId'] == $userId) {
                $this->delete($item['id']);
            }
        }
    }

    public function getByType($type)
    {
        $items = $this->getAllTokens();
        if (empty($items)) {
            return null;
        }
        $ids = ArrayToolkit::column($items, 'id');
        array_multisort($ids, SORT_DESC, $ids);
        $token = null;
        foreach ($items as $item) {
            if ($item['type'] === $type) {
                $token = $item;
                break;
            }
        }

        return $this->unSerialize($token);
    }

    public function deleteTopsByExpiredTime($expiredTime, int $limit)
    {
        $items = $this->getAllTokens();
        $delItems = array_slice($items, 0, $limit);
        foreach ($delItems as $item) {
            $this->delete($item['id']);
        }
    }

    public function deleteByTypeAndUserId($type, $userId)
    {
        $items = $this->getAllTokens();
        foreach ($items as $item) {
            if ($item['userId'] == $userId && $item['type'] == $type) {
                $this->delete($item['id']);
            }
        }
    }

    public function getLastedByUserIDAndType($userId, $type)
    {
        $items = $this->getAllTokens();
        if(empty($items)) {
            return null;
        }
        $ids = ArrayToolkit::column($items, 'id');
        array_multisort($ids, SORT_DESC, $items);
        $token = null;
        foreach ($items as $item) {
            if ($item['type'] === $type && $item['userId'] == $userId) {
                $token = $item;
                break;
            }
        }

        return $token;
    }

    protected function getAllTokens()
    {
        // 获取哈希表中所有键名
        $pattern = sprintf("/^%s:/", self::HASH_TABLE_NAME);
        $setMembers = $this->getRedis()->sMembers(self::HASH_TABLE_NAME);
        $keys = preg_grep($pattern, $setMembers);
        // 遍历键名，获取对应的哈希表值
        $list = [];
        foreach ($keys as $key) {
            $value = $this->getRedis()->hGetAll($key);
            if (!empty($value)) {
                $list[] = $this->unSerialize($value);
            }

        }

        return $list;
    }

    protected function unSerialize($token)
    {
        if (is_array($token) && !empty($token['data']) && is_string($token['data'])) {
            $unserialized = @unserialize($token['data']);
            // 如果 unserialize 成功，并且返回值是一个数组或对象，则说明是序列化字符串
            if ($unserialized !== false && is_array($unserialized)) {
                $token['data'] = $unserialized;
            } else {
                $token['data'] = $token['data'];
            }

            return $token;
        }

        return $token;
    }
}