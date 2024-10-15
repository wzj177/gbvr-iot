<?php


namespace CoreW\Business\User\Token\Storage;


use CoreW\Business\User\Exception\UserException;
use CoreW\Context\BfwAware;
use support\Redis;
use support\utils\ArrayToolkit;

class RedisTokenStorage implements TokenStorageInterface
{
    use BfwAware;

    const HASH_TABLE_NAME = 'vr_user_tokens';
    const COUNT_KEY = 'vr_user_tokens:count';

    use \CoreW\Traits\Token\RedisTokenStorage;

    /**
     * @return Redis
     */
    protected function getRedis()
    {
        return Redis::connection('admin_token');
    }
}