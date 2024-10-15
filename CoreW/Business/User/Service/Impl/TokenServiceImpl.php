<?php


namespace CoreW\Business\User\Service\Impl;


use CoreW\Business\BaseService;
use CoreW\Business\User\Dao\TokenDao;
use CoreW\Business\User\Token\Storage\DbTokenStorage;
use CoreW\Business\User\Token\Storage\RedisTokenStorage;
use CoreW\Business\User\Token\Storage\TokenStorageInterface;
use CoreW\Business\User\Service\TokenService;
use CoreW\Exception\UnexpectedValueException;
use Ramsey\Uuid\Uuid;
use support\utils\ArrayToolkit;
use Webman\Config;

class TokenServiceImpl extends BaseService implements TokenService
{
    private $storageMap = [
        'db' => DbTokenStorage::class,
        'redis' => RedisTokenStorage::class
    ];

    public function createToken(array $fields)
    {
        return $this->getTokenStorage()->create($fields);
    }

    public function makeToken($type, array $args = [])
    {
        $token = [];
        $token['type'] = $type;
        $token['token'] = $this->_makeTokenValue();
        $token['data'] = !isset($args['data']) ? '' : $args['data'];
        $token['times'] = empty($args['times']) ? 0 : (int)$args['times'];
        $token['remainedTimes'] = $token['times'];
        $token['userId'] = empty($args['userId']) ? 0 : $args['userId'];
        $token['expiredTime'] = empty($args['duration']) ? 0 : time() + $args['duration'];
        $token['createdTime'] = time();

        return $this->getTokenStorage()->create($token);
    }

    public function verifyToken($type, $value)
    {
//        var_dump($type, $value);
        $token = $this->getTokenStorage()->getByToken($value);
//        var_dump($token);

        if (empty($token)) {
            return false;
        }

        if ($token['type'] != $type) {
            return false;
        }

        if (($token['expiredTime'] > 0) && ($token['expiredTime'] < time())) {
            return false;
        }

        if ($token['remainedTimes'] > 1) {
            $this->getTokenStorage()->wave(array($token['id']), array('remainedTimes' => -1));
        }

        $this->_gcToken($token);

        return $token;
    }

    public function destroyToken($token)
    {
        $tokenItem = $this->getTokenStorage()->getByToken($token);

        if (empty($tokenItem)) {
            return;
        }
        $tokenStorage = $this->getTokenStorage();
        $tokenStorage->delete($tokenItem['id']);
//        if ($tokenStorage instanceof DbTokenStorage) {
//            $tokenStorage->delete($tokenItem['id']);
//        }
//
//        if ($tokenStorage instanceof RedisTokenStorage) {
//            $this->getTokenStorage()->deleteByToken($tokenItem);
//        }

    }

    public function findTokensByUserIdAndType($userId, $type)
    {
        return $this->getTokenStorage()->findByUserIdAndType($userId, $type);
    }

    public function destroyTokensByUserId($userId)
    {
        return $this->getTokenStorage()->destroyTokensByUserId($userId);
    }

    public function getTokenByType($type)
    {
        return $this->getTokenStorage()->getByType($type);
    }

    public function deleteTokenByTypeAndUserId($type, $userId)
    {
        return $this->getTokenStorage()->deleteByTypeAndUserId($type, $userId);
    }

    public function deleteExpiredTokens($limit)
    {
        $this->getTokenStorage()->deleteTopsByExpiredTime(time(), $limit);
    }

    protected function _gcToken($token)
    {
        if (($token['times'] > 0) && ($token['remainedTimes'] <= 1)) {
            $this->getTokenStorage()->delete($token['id']);

            return;
        }

        if (($token['expiredTime'] > 0) && ($token['expiredTime'] < time())) {
            $this->getTokenStorage()->delete($token['id']);
        }
    }

    protected function _makeTokenValue()
    {
        $uuid = Uuid::uuid1();

        return $uuid->getHex();
    }


    public function getLastedTokenByUserIDAndType($userId, $type)
    {
        return $this->getTokenStorage()->getLastedByUserIDAndType($userId, $type);
    }

    /**
     * @return TokenStorageInterface
     */
    public function getTokenStorage()
    {
        $storage = config('app.token_storage');
        if (!isset($this->storageMap[$storage])) {
            throw new UnexpectedValueException('token storage not exist');
        }

        $obj = new $this->storageMap[$storage]();
        $obj->setBfw($this->bfw);

        return $obj;
    }
}