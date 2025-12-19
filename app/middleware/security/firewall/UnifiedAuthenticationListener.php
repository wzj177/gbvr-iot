<?php

namespace app\middleware\security\firewall;

use CoreW\Bfw as Biz;
use CoreW\Business\Auth\Handler\TokenHandlerInterface;
use CoreW\Business\BizEnum;
use CoreW\Business\User\CurrentUser as AdminCurrentUser;
use CoreW\Business\VIP\CurrentUser as VipCurrentUser;
use CoreW\Business\User\Service\UserService;
use CoreW\Business\VIP\Service\VIPService;
use CoreW\Business\User\Exception\UserException as AdminUserException;
use CoreW\Business\VIP\Exception\VIPException;
use app\middleware\security\authentication\token\ApiToken;
use support\Request;
use support\utils\StringToolkit;
use CoreW\Core;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class UnifiedAuthenticationListener
{
    protected $tokenKey = 'x-auth-token';
    protected $tokenTypeKey = 'x-auth-type';
    protected $authType = null;
    protected $userIdKey = 'userId';
    const TOKEN_LENGTH = 32;

    protected $handler = 'default';
    protected $isApiRequest = false;
    protected $jwtRefreshData = null;
    protected $biz;

    public function __construct(Biz $biz, $isApiRequest = false)
    {
        $this->biz = $biz;
        $this->isApiRequest = $isApiRequest;
        // 根据请求类型选择不同的token处理器配置
        $this->handler = $this->isApiRequest ?
            config('auth.api_token_handler') :
            config('auth.admin_token_handler');

        if ($this->handler === 'jwt') {
            $this->tokenKey = 'authorization';
        }
    }

    public function handle(Request $request)
    {
        // 检查是否为 Basic Auth
        $authorization = $request->header('authorization', null);
        if ($authorization && str_contains(strtolower($authorization), 'basic')
            && null === $request->header('x-auth-token', null)) {
            return $this->handleBasicAuth($request, $authorization);
        }
        
        // 否则处理 Token Auth
        return $this->handleTokenAuth($request);
    }

    protected function handleBasicAuth(Request $request, string $authorizationHeader)
    {
        if ($this->isApiRequest) {
            // API端不处理Basic Auth
            return true;
        }
        
        list($username, $password) = StringToolkit::parseBasicAuthData(
            str_replace('Basic ', '', $authorizationHeader)
        );
        
        if (empty($username) || empty($password)) {
            throw AdminUserException::USERNAME_PASSWORD_ERROR();
        }
        
        $user = $this->getUserService()->getUserByNickname($username);
        if (!$user || !$this->getUserService()->verifyInSaltOut($password, $user['salt'], $user['password'])) {
            throw AdminUserException::USERNAME_PASSWORD_ERROR();
        }
        
        if ($user['locked']) {
            throw AdminUserException::LOCKED_USER();
        }
        
        $currentUser = new AdminCurrentUser();
        $user['currentIp'] = $request->getRealIp();
        $currentUser->fromArray($user);
        
        $token = new ApiToken($currentUser, $currentUser->getRoles());
        $this->setToken($token);

        return true;
    }

    protected function handleTokenAuth(Request $request): bool
    {
        $token = $request->header($this->tokenKey);
        if (empty($token)) {
            if ($this->isApiRequest) {
                throw VIPException::EXPIRED_OR_NOTFOUND_TOKEN();
            } else {
                throw AdminUserException::EXPIRED_OR_NOTFOUND_TOKEN();
            }
        }

        if (!$this->authType) {
            if ($this->isApiRequest) {
                // API端默认使用VIP登录类型
                $authType = $request->header($this->tokenTypeKey, BizEnum::TOKEN_TYPE_VIP_PC_LOGIN);
            } else {
                // Admin端默认使用管理端登录类型
                $authType = $request->header($this->tokenTypeKey, BizEnum::TOKEN_TYPE_ADMIN_LOGIN);
            }
        } else {
            $authType = $this->authType;
        }

        if ($this->handler === 'default' && strlen($token) !== self::TOKEN_LENGTH) {
            throw AdminUserException::TOKEN_PARAMS_FAILED();
        }

        $rawToken = $this->verifyToken($authType, $token);
        if (empty($rawToken)) {
            if ($this->isApiRequest) {
                throw VIPException::EXPIRED_OR_NOTFOUND_TOKEN();
            } else {
                throw AdminUserException::EXPIRED_OR_NOTFOUND_TOKEN();
            }
        }

        // jwt token access_token 过期，需要刷新token
        if ($this->handler === 'jwt' && isset($rawToken['refresh']) && $rawToken['refresh']) {
            // 自动续签
            $rawToken = $this->refreshAccessToken($rawToken['oldToken']);

            $token = $this->createApiTokenFromRequest($request, $rawToken[$this->userIdKey], $rawToken['token']);
            $this->setToken($token);

            // 保存续签数据供中间件使用
            $this->jwtRefreshData = [
                'token' => $rawToken['token'],
                'type' => $rawToken['type']
            ];

            return $rawToken;
        }

        $token = $this->createApiTokenFromRequest($request, $rawToken[$this->userIdKey], $rawToken['token']);

        $this->setToken($token);

        return true;
    }

    protected function setToken(ApiToken $token)
    {
        $this->getApiTokenStorage()->setToken($token);
        $this->getBiz()->offsetSet('user', $this->getApiTokenStorage()->getToken()->getUser());
    }

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
     * @param array|int $userId
     * @param string $loginToken
     * @return ApiToken
     */
    protected function createApiTokenFromRequest(Request $request, array|int $userId, string $loginToken = ''): ApiToken
    {
        if ($this->isApiRequest) {
            // API端处理VIP用户
            $user = $this->getVIPService()->getVIPById($userId);
            if ((int)$user['status'] === BizEnum::DISABLED) {
                throw VIPException::LOCKED_USER();
            }

            $currentUser = new VipCurrentUser();
            $user['currentIp'] = $request->getRealIp();
            $user['loginToken'] = $loginToken;
            $currentUser->fromArray($user);
        } else {
            // Admin端处理管理员用户
            $user = $this->getUserService()->getUser($userId);
            if ($user['locked']) {
                throw AdminUserException::LOCKED_USER();
            }

            $currentUser = new AdminCurrentUser();
            $user['currentIp'] = $request->getRealIp();
            $user['loginToken'] = $loginToken;
            $currentUser->fromArray($user);
        }

        return new ApiToken($currentUser, $currentUser->getRoles());
    }

    /**
     * @return TokenHandlerInterface
     */
    protected function getTokenHandler()
    {
        if ($this->isApiRequest) {
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
        } else {
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

            if (config('app.admin.private_key')) {
                $config['private_key'] = config('app.admin.private_key');
            }

            if (config('app.admin.public_key')) {
                $config['public_key'] = config('app.admin.public_key');
            }
            
            $tokenHandler->setConfig($config);
            return $tokenHandler;
        }
    }

    /**
     * @return UserService
     */
    protected function getUserService()
    {
        return $this->biz->service('User:UserService');
    }
    
    /**
     * @return VIPService
     */
    protected function getVIPService()
    {
        return $this->biz->service('VIP:VIPService');
    }

    /**
     * @return TokenStorageInterface
     */
    protected function getApiTokenStorage()
    {
        return $this->biz->offsetGet('api.security.token_storage');
    }

    /**
     * @return Biz
     */
    protected function getBiz()
    {
        return Core::instance();
    }
}