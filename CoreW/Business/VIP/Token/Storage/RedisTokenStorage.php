<?php


namespace CoreW\Business\VIP\Token\Storage;


use CoreW\Business\VIP\Exception\VIPException;
use CoreW\Context\BfwAware;
use support\Redis;
use support\utils\ArrayToolkit;

class RedisTokenStorage implements TokenStorageInterface
{
    use BfwAware;

    const HASH_TABLE_NAME = 'gv_vip_tokens';
    const COUNT_KEY = 'gv_vip_tokens:count';

    use \CoreW\Traits\Token\RedisTokenStorage;

    /**
     * @return Redis
     */
    protected function getRedis()
    {
        return Redis::connection('vip_token');
    }
}