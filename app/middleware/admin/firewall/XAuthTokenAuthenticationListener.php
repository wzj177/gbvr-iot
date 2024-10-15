<?php


namespace app\middleware\admin\firewall;


use app\middleware\security\authentication\token\ApiToken;
use app\middleware\security\firewall\AbstractXAuthTokenAuthenticationListener;
use CoreW\Business\BizEnum;
use CoreW\Business\Auth\Handler\TokenHandlerInterface;
use CoreW\Business\User\CurrentUser;
use CoreW\Business\User\Service\UserService;
use support\Request;

class XAuthTokenAuthenticationListener extends AbstractXAuthTokenAuthenticationListener
{

    protected $authType = BizEnum::TOKEN_TYPE_ADMIN_LOGIN;

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
     * @param int|array $userId
     * @param string $loginToken
     * @return ApiToken
     */
    protected function createApiTokenFromRequest(Request $request, $userId, $loginToken = '')
    {
        $user = $this->getUserService()->getUser($userId);
        if ($user['locked']) {
            throw \CoreW\Business\User\Exception\UserException::LOCKED_USER();
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
        $tokenHandler = $this->biz['admin_auth']();
        $config = [];
        if (config('app.admin.jwt_ttl')) {
            $config['ttl'] = config('app.admin.jwt_ttl');
        }

        if (config('app.admin.jwt_refresh_ttl')) {
            $config['refresh_ttl'] = config('app.admin.jwt_refresh_ttl');
        }

        if (config('app.admin.jwt_algo')) {
            $config['algo'] = config('app.admin.jwt_algo');
        }

        if (config('app.admin.secret')) {
            $config['secret'] = config('app.admin.secret');
        }

        if (config('app.admin.secret')) {
            $config['secret'] = config('app.admin.secret');
        }

        if (config('app.admin.private_key')) {
            $config['private_key'] = config('app.admin.private_key');
        }

        if (config('app.admin.public_key')) {
            $config['public_key'] = config('app.admin.public_key');
        }

        $tokenHandler->setConfig($config);

        return $tokenHandler;
    }

    /**
     * @return UserService
     */
    protected function getUserService()
    {
        return $this->biz->service('User:UserService');
    }
}