<?php


namespace CoreW\Business\Auth\Handler;


class JwtTokenHandler extends BaseTokenHandler implements TokenHandlerInterface
{

    /**
     * 刷新token
     *
     * @param $oldToken 旧的token
     */
    public function refreshAccessToken($oldToken)
    {
        $payload = [
            'iss' => $oldToken['type'],
            'userId' => $oldToken['userId']
        ];
        $payload['sub'] = 'jwt_' . $payload['userId'];
        $accessToken = $this->getJwtManager()->refreshAccessToken($payload);
        $this->getTokenService()->destroyToken($oldToken['token']);
        $this->getTokenService()->createToken([
            'type' => $payload['iss'],
            'token' => md5($accessToken),
            'data' => [
                'access_token' => $accessToken,
                'refresh_token' => $oldToken['data']['refresh_token']
            ],
            'times' => 0,
            'remainedTimes' => 0,
            'userId' => $payload['userId'],
            'expiredTime' => $oldToken['expiredTime'],
            'createdTime' => time()
        ]);

        return [
            'token' => 'Bearer ' . $accessToken,
            'key' => 'Authorization',
            'type' => $payload['iss'],
            'userId' => $payload['userId']
        ];
    }

    /**
     * 生成一个一次性的Token.
     *
     * @param string $type Token类型
     * @param array $args 生成Token的一些限制规则
     *
     * @return array 生成的Token
     */
    public function makeToken($type, array $args = [])
    {
        $payload = [
            'iss' => $type,
            'userId' => $args['userId'],
        ];
        $payload['sub'] = 'jwt_' . $payload['userId'];
        $tokenResult = $this->getJwtManager()->makeToken($payload);
        $refreshPayload = $tokenResult['refreshPayload'];
        $accessToken = $tokenResult['accessToken'];
        $refreshToken = $tokenResult['refreshToken'];
        $md5ID = md5($accessToken);
        // 登录就存入refresh token，通过type 和 userId 查询
        $this->getTokenService()->createToken([
            'type' => $payload['iss'],
            'token' => $md5ID,
            'data' => [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken
            ],
            'times' => 0,
            'remainedTimes' => 0,
            'userId' => $payload['userId'],
            'expiredTime' => $refreshPayload['exp'],
            'createdTime' => time()
        ]);

        return [
            'token' => 'Bearer ' . $accessToken,
            'md5_token' => $md5ID,
            'key' => 'Authorization',
            'type' => $payload['iss']
        ];
    }

    /**
     * 校验Token.
     *
     * @param string $type Token类型
     * @param string $value Token的值
     *
     * @return bool 该Token值是否OK
     */
    public function verifyToken($type, $value)
    {
        $token = substr($value, strlen("Bearer "));
        $dbToken = $this->getTokenService()->verifyToken($type, md5($token));
        if (empty($dbToken)) {
            return null;
        }


        $result = $this->getJwtManager()->verifyToken($token);
        if ($result === -1) {
            return null;
        }

        if ($result === 0) {
            return [
                'refresh' => true,
                'oldToken' => $dbToken
            ];
        }

        if ((int)$result->userId !== (int)$dbToken['userId']) {
            // 串号
//            echo 'token与用户不匹配';
            return null;
        }

        return [
            'token' => $value,
            'userId' => $dbToken['userId']
        ];
    }

    /**
     * 作废一个Token.
     *
     * @param [type] $value 要摧毁的Token的值
     */
    public function destroyToken($value)
    {
        $this->getTokenService()->destroyToken($value);
    }

    public function deleteExpiredTokens($limit)
    {
    }

    public function findTokensByUserIdAndType($userId, $type)
    {
        return $this->getTokenService()->findTokensByUserIdAndType($userId, $type);
    }

    public function destroyTokensByUserId($userId)
    {
    }

    public function getTokenByType($type)
    {
    }

    public function deleteTokenByTypeAndUserId($type, $userId)
    {
    }


    public function getLastedTokenByTypeAndUserId($type, $userId)
    {
        return $this->getTokenService()->getLastedTokenByUserIDAndType($userId, $type);
    }

    /**
     *
     * @return JwtManager
     */
    protected function getJwtManager()
    {
        return new JwtManager(array_merge(config('jwt', []), $this->config));
    }
}