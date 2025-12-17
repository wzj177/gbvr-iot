<?php

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\filters\TokenFilter;
use CoreW\Business\Common\UserException;
use CoreW\Business\BizEnum;
use CoreW\Business\DataFilters\Filter;
use CoreW\Business\Auth\Handler\TokenHandlerInterface;
use support\Redis;
use support\Request;
use Webman\Captcha\CaptchaBuilder;

class AuthController extends BaseController
{

    /**
     *  登录接口
     *
     * @param Request $request
     * @return \support\Response
     */
    public function login(Request $request)
    {
        $type = $request->get('type', BizEnum::TOKEN_TYPE_ADMIN_LOGIN);
        $authConfig = $this->getSettingService()->get('auth', []);
        $user = $this->getCurrentUser()->toArray();
        $currentIp = $request->getRealIp();
        // 用户登录ip限制:开启后同一帐号只能在一处进行登录
        $loginLimit = isset($authConfig['login_connect_login_limit']) && $authConfig['login_connect_login_limit'];
        // 开启用户登录限制，同时上次登录ip不是当前ip
        if ($loginLimit && $user['loginIp'] !== $currentIp) {
            $lastedToken = $this->getTokenHandler()->getLastedTokenByTypeAndUserId($type, $user['id']);
            // 存在上次登录的token
            if (!empty($lastedToken)) {
                throw UserException::LIMIT_LOGIN();
            }
        }

        $token = $this->makeAuthToken($type, $user['id'], config('app.admin.auth_ttl'));
//            $this->getUserService()->markLoginInfo($user, $type);
        // 设备终端登录限制:开启后，同一帐号同时只能在APP或WEB一个设备终端上进行登录
        $clientLoginLimit = isset($authConfig['login_connect_client_login_limit']) && $authConfig['login_connect_client_login_limit'];
        if ($clientLoginLimit) {
            $delTokens = $this->getTokenHandler()->findTokensByUserIdAndType($user['id'], $type);
            foreach ($delTokens as $delToken) {
                if (isset($token['md5_token'])) {
                    if ($delToken['token'] != $token['md5_token']) {
                        $this->getTokenHandler()->destroyToken($delToken['token']);
                    }
                } elseif ($delToken['token'] != $token['token']) {
                    $this->getTokenHandler()->destroyToken($delToken['token']);
                }
            }
        }

        $user['currentIp'] = $currentIp;
        $data = [
            'token' => [
                'value' => $token['token'],
                'type' => $token['type'],
                'key' => $token['key']
            ],
            'user' => $user,
        ];


        $tokenFilter = new TokenFilter();

        $tokenFilter->filter($data);


        return $this->createSuccessJsonResponse($data, '登录成功');
    }

    /**
     * 退出登录
     *
     * @param Request $request
     * @return \support\Response
     */
    public function logout(Request $request)
    {
        $user = $this->getCurrentUser()->toArray();
        $this->getLogService()->info('admin', 'user_logout', '用户退出', [
            'user' => $user,
            'currentIp' => $request->getRealIp()
        ]);
        $this->getTokenHandler()->destroyToken($user['loginToken']);
        $this->getBiz()->offsetSet('user', null);

        return $this->createSuccessJsonResponse(null, '登出成功');
    }

    public function captcha(Request $request)
    {
        // 初始化验证码类
        $builder = new CaptchaBuilder;
        // 生成验证码
        $builder->build();
        // 将验证码的值存储到redis中
        Redis::set('admin:captcha', strtolower($builder->getPhrase()));
        // 获得验证码图片二进制数据
        $img_content = $builder->get();
        // 输出验证码二进制数据
        return response($img_content, 200, ['Content-Type' => 'image/jpeg']);
    }

    protected function makeAuthToken($type, $userId, $duration = 0, $data = null, $args = [])
    {
        $token = [];
        $token['userId'] = $userId ? (int)$userId : 0;
        $token['data'] = $data;
        $token['times'] = empty($args['times']) ? 0 : (int)($args['times']);
        $token['duration'] = $duration;

        return $this->getTokenHandler()->makeToken($type, $token);
    }

    /**
     * 校验用户
     *
     * @param Request $request
     * @param $admin
     * @param $password
     * @return mixed
     */
    protected function validateUser(Request $request, $admin, $password)
    {
        if (empty($admin) || empty($password)) {
            throw UserException::USERNAME_PASSWORD_ERROR();
        }

        $checkCaptcha = $request->post('checkCaptcha', false);

        if ($checkCaptcha) {
            $captcha = $request->post('captcha');
            if (empty($captcha)) {
                throw UserException::CAPTCHA_EMPTY();
            }

            if (strtolower($captcha) !== Redis::get('admin:captcha')) {
                throw UserException::CAPTCHA_ERROR();
            }
        }

        $user = $this->getUserService()->getUserByLoginField($admin);
        if (empty($user)) {
            throw UserException::NOTFOUND_USER();
        }

        if (!$this->getUserService()->verifyPasswordByUser($user, $password)) {
            // TODO: 登录保护：输错5次锁定用户
            throw UserException::PASSWORD_ERROR();
        }


        if ($user['locked']) {
            throw UserException::LOCKED_USER();
        }

        return $user;
    }

    /**
     * @return TokenHandlerInterface
     */
    protected function getTokenHandler()
    {
        return $this->getBiz()->offsetGet('admin_auth')();
    }
}
