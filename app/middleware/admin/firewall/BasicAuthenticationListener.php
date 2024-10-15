<?php


namespace app\middleware\admin\firewall;


use app\middleware\security\authentication\token\ApiToken;
use app\middleware\security\firewall\AbstractBasicAuthenticationListener;
use CoreW\Business\User\CurrentUser;
use CoreW\Business\User\Exception\UserException;
use CoreW\Business\User\Service\UserService;
use Support\Request;

class BasicAuthenticationListener extends AbstractBasicAuthenticationListener
{
    use LoginTrait;

    /**
     * 生成api token
     *
     * @param Request $request
     * @param $user
     * @param string $loginToken
     * @return ApiToken
     */
    protected function createApiTokenFromRequest(Request $request, $user, $loginToken = '')
    {
        $currentUser = new CurrentUser();
        $user['currentIp'] = $request->getRealIp();
        $user['loginToken'] = $loginToken;
        $currentUser->fromArray($user);

        return new ApiToken($currentUser, $currentUser->getRoles());
    }

    /**
     * @return UserService
     */
    protected function getUserService()
    {
        return $this->biz->service('User:UserService');
    }
}