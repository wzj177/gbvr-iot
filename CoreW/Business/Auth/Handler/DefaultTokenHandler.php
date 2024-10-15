<?php


namespace CoreW\Business\Auth\Handler;


use Ramsey\Uuid\Uuid;

class DefaultTokenHandler extends BaseTokenHandler implements TokenHandlerInterface
{

    public function refreshAccessToken($oldToken)
    {
        return;
    }

    public function makeToken($type, array $args = [])
    {
        $token = $this->getTokenService()->makeToken($type, $args);
        $token['key'] = 'X-Auth-Token';

        return $token;
    }

    public function verifyToken($type, $value)
    {
        return $this->getTokenService()->verifyToken($type, $value);
    }

    public function destroyToken($token)
    {
        $this->getTokenService()->destroyToken($token);
    }

    public function findTokensByUserIdAndType($userId, $type)
    {
        return $this->getTokenService()->findTokensByUserIdAndType($userId, $type);
    }

    public function destroyTokensByUserId($userId)
    {
        return $this->getTokenService()->destroyTokensByUserId($userId);
    }

    public function getTokenByType($type)
    {
        return $this->getTokenService()->getTokenByType($type);
    }

    public function deleteTokenByTypeAndUserId($type, $userId)
    {
        return $this->getTokenService()->deleteTokenByTypeAndUserId($type, $userId);
    }

    public function deleteExpiredTokens($limit)
    {
        $this->getTokenService()->deleteExpiredTokens(time(), $limit);
    }

    public function getLastedTokenByTypeAndUserId($type, $userId)
    {
        return $this->getTokenService()->getLastedTokenByUserIDAndType($userId, $type);
    }
}