<?php


namespace app\middleware\security\firewall;


use support\Request;
use support\utils\StringToolkit;

abstract class AbstractBasicAuthenticationListener extends AbstractAuthenticationListener
{
    public function handle(Request $request)
    {
        $authorization = $request->header('authorization', '');
        list($admin, $password) = $this->parseAuthData(str_replace('Basic ', '', $authorization));
        $user = $this->validateUser($request, $admin, $password);
        // 登记CurrentUser
        $token = $this->createApiTokenFromRequest($request, $user);
        // 登录接口才记录token
        if (in_array($request->route->getName(), ['admin.login', 'api.login'])) {
            $this->getApiTokenStorage()->setToken($token);
        }

        return true;

    }

    protected function parseAuthData(string $authorization): array
    {
        return StringToolkit::parseBasicAuthData($authorization);
    }
}