<?php

namespace app\middleware\admin;

use app\middleware\security\authentication\token\ApiToken;
use CoreW\Business\User\CurrentUser as AdminCurrentUser;
use CoreW\Business\User\Exception\UserException as AdminUserException;
use CoreW\Core;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;
use CoreW\Business\User\Service\UserService;

class OpenApiAuth implements MiddlewareInterface
{
    public function process(Request $request, callable $next) : Response
    {
        try {
            $this->handle($request);
            return $next($request);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    protected function handle(Request $request) : bool
    {
        // 1. 检查 X-API-Key header
        $apiKey = $request->header('x-api-key');
        if (empty($apiKey)) {
            // fallback: 支持 query param
            $apiKey = $request->get('api_key');
        }

        if (empty($apiKey)) {
            throw AdminUserException::API_KEY_MISSING();
        }

        // 2. 查询用户
        $user = $this->getUserService()->getUserByApiKey($apiKey);
        if (!$user) {
            throw AdminUserException::API_KEY_INVALID();
        }

        // 3. 验证 API 访问是否启用
        if (empty($user['api_enabled'])) {
            throw AdminUserException::API_KEY_DISABLED();
        }

        // 4. 验证用户未被锁定
        if ($user['locked']) {
            throw AdminUserException::LOCKED_USER();
        }

        // 5. 设置用户认证上下文
        $currentUser = new AdminCurrentUser();
        $user['currentIp'] = $request->getRealIp();
        $currentUser->fromArray($user);

        $token = new ApiToken($currentUser, $currentUser->getRoles());
        $this->setToken($token);

        return true;
    }

    protected function setToken(ApiToken $token)
    {
        $this->getApiTokenStorage()->setToken($token);
        $this->getBiz()->offsetSet('user', $this->getApiTokenStorage()->getToken()->getUser());
    }

    protected function getUserService(): UserService
    {
        return $this->getBiz()->service('User:UserService');
    }

    protected function getApiTokenStorage()
    {
        return $this->getBiz()->offsetGet('api.security.token_storage');
    }

    protected function getBiz()
    {
        return Core::instance();
    }
}
