<?php


namespace app\middleware\api\firewall;


use app\middleware\security\authentication\token\ApiToken;
use app\middleware\security\firewall\AbstractXAuthTokenAuthenticationListener;
use CoreW\Business\Auth\Handler\TokenHandlerInterface;
use CoreW\Business\BizEnum;
use CoreW\Business\VIP\CurrentUser;
use CoreW\Business\VIP\Exception\VIPException;
use CoreW\Business\VIP\Service\VIPService;
use support\Request;

class XAuthTokenAuthenticationListener  extends AbstractXAuthTokenAuthenticationListener
{
    protected $authType = BizEnum::TOKEN_TYPE_VIP_PC_LOGIN;

    protected $userIdKey = 'vipId';

    protected function verifyToken($authType, $token)
    {
        return $this->getTokenHandler()->verifyToken($authType, $token);
    }

    protected function refreshAccessToken($rawToken)
    {
        return $this->getTokenHandler()->refreshAccessToken($rawToken);
    }

    /**
     * 生成api token
     *
     * @param Request $request
     * @param * @param int|array $userId
     * @param string $loginToken
     * @return ApiToken
     */
    protected function createApiTokenFromRequest(Request $request, $userId, $loginToken = '')
    {
        $user = $this->getVIPService()->getVIPById($userId);
        if ((int)$user['status'] === BizEnum::DISABLED) {
            throw VIPException::LOCKED_USER();
        }

        $currentUser = new CurrentUser();
        $user['currentIp'] = $request->getRealIp();
        $user['loginToken'] = $loginToken;
        $currentUser->fromArray($user);

        return new ApiToken($currentUser, $currentUser->getRoles());
    }

    protected function validateUser(Request $request, $admin, $password)
    {
    }

    /**
     * @return TokenHandlerInterface
     */
    protected function getTokenHandler()
    {
        $tokenHandler = $this->biz['api_auth']();
        $config = [];
        if (config('app.api.jwt_ttl')) {
            $config['ttl'] = config('app.api.jwt_ttl');
        }

        if (config('app.api.jwt_refresh_ttl')) {
            $config['refresh_ttl'] = config('app.api.jwt_refresh_ttl');
        }

        if (config('app.api.jwt_algo')) {
            $config['algo'] = config('app.api.jwt_algo');
        }

        if (config('app.api.secret')) {
            $config['secret'] = config('app.admin.secret');
        }

        if (config('app.api.secret')) {
            $config['secret'] = config('app.api.secret');
        }

        if (config('app.api.private_key')) {
            $config['private_key'] = config('app.api.private_key');
        }

        if (config('app.api.public_key')) {
            $config['public_key'] = config('app.api.public_key');
        }

        $tokenHandler->setConfig($config);

        return $tokenHandler;
    }

    /**
     * @return VIPService
     */
    protected function getVIPService()
    {
        return $this->biz->service('VIP:VIPService');
    }
}