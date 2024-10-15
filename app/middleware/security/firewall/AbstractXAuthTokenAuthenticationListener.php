<?php


namespace app\middleware\security\firewall;


use app\middleware\security\authentication\token\ApiToken;
use CoreW\Bfw as Biz;
use CoreW\Business\BizEnum;
use CoreW\Business\Common\UserException;
use support\Request;

abstract class AbstractXAuthTokenAuthenticationListener extends AbstractAuthenticationListener
{
    protected $tokenKey = 'x-auth-token';
    protected $tokenTypeKey = 'x-auth-type';
    protected $authType = null;
    protected $userIdKey = 'userId';
    const TOKEN_LENGTH = 32;

    protected $handler = 'default';

    public function __construct(Biz $biz)
    {
        parent::__construct($biz);
        $this->handler = config('auth.token_handler');
        if ($this->handler === 'jwt') {
            $this->tokenKey = 'authorization';
        }
    }

    public function handle(Request $request)
    {
        $token = $request->header($this->tokenKey);
        if (empty($token)) {
            throw  UserException::EXPIRED_OR_NOTFOUND_TOKEN();
        }

        if (!$this->authType) {
            // 针对api多端登录
            $authType = $request->header($this->tokenTypeKey, BizEnum::TOKEN_TYPE_H5_LOGIN);
        } else {
            $authType = $this->authType;
        }

        $tokenItems = BizEnum::getLoginTypeItems();
        if ($this->handler === 'default' && strlen($token) !== self::TOKEN_LENGTH) {
            throw UserException::TOKEN_PARAMS_FAILED();
        }

//        if (!array_key_exists($authType, $tokenItems)) {
//            throw UserException::TOKEN_PARAMS_FAILED();
//        }

        $rawToken = $this->verifyToken($authType, $token);
        if (empty($rawToken)) {
            throw  UserException::EXPIRED_OR_NOTFOUND_TOKEN();
        }

        // jwt token access_token 过期，需要刷新token
        if ($this->handler === 'jwt' && isset($rawToken['refresh']) && $rawToken['refresh']) {
            // 自动续签
            $rawToken = $this->refreshAccessToken($rawToken['oldToken']);

            $token = $this->createApiTokenFromRequest($request, $rawToken[$this->userIdKey], $rawToken['token']);
            $this->getApiTokenStorage()->setToken($token);

            return $rawToken;
        }

        $token = $this->createApiTokenFromRequest($request, $rawToken[$this->userIdKey], $rawToken['token']);
        $this->getApiTokenStorage()->setToken($token);

        return true;

    }

    abstract protected function verifyToken($authType, $token);


    protected function refreshAccessToken($rawToken)
    {
    }
}