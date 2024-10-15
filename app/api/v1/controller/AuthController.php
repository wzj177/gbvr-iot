<?php

namespace app\api\v1\controller;

use app\api\BaseController;
use app\api\filters\VIPFilter;
use CoreW\Business\BizEnum;
use CoreW\Business\DataFilters\Filter;
use CoreW\Business\User\Exception\UserException;
use CoreW\Business\VIP\Dao\LoginFormDto;
use CoreW\Oauth2\Client\QQOauthClient;
use Respect\Validation\Validator as v;
use support\Redis;
use support\Request;

class AuthController extends BaseController
{
    public function index(Request $request)
    {
        return response('hello world：' . $request->getUserAgent());
    }

    /**
     * 授权配置
     *
     * @param Request $request
     * @return \support\Response
     */
    public function config(Request $request)
    {
        return $this->createSuccessJsonResponse($this->getSettingService()->get('auth'));
    }

    public function qqAuthUrl(Request $request)
    {
        $authConfig = $this->getSettingService()->get('auth');
        if (!empty($authConfig) && !$authConfig['oauth_login_enabled']) {
            return $this->createErrorJsonResponse('获取qq授权登录地址失败，未开启该功能');
        }

        /** @var $oauth2QQ QQOauthClient */
        $oauth2QQ = $this->getBiz()->offsetGet('oauth2_qq');

        $url = $request->post('redirect_url', null);
        if (empty($url)) {
            return $this->createErrorJsonResponse('获取qq授权登录地址失败，回调地址参数缺失');
        }

        return $this->createSuccessJsonResponse([
            'url' => $oauth2QQ->getAuthorizationUrl($url)
        ]);
    }

    public function qqLogin(Request $request)
    {
        $code = $request->post('code');
        $state = $request->post('state', 'wanzi');
        $redirectUri = $request->post('redirect_uri', '');
        $defaultType = $request->isWechat() ? BizEnum::TOKEN_TYPE_WECHAT_LOGIN : BizEnum::TOKEN_TYPE_VIP_PC_LOGIN;
        $type = $request->get('type', $defaultType);
        $dto = new LoginFormDto();
        $dto->mode = 'oauth2_qq';
        $dto->username = $code;
        $dto->oauth2Code = $code;
        $dto->oauth2State = $state;
        $dto->oauth2RedirectUri = $redirectUri;
        $dto->requestIp = $request->getRealIp();
        $dto->clientType = $type;
        list($vip, $token) = $this->getVIPService()->login($dto);
        $filter = new VIPFilter();
        $filter->filter($vip);

        return $this->createSuccessJsonResponse([
            'token' => $token,
            'user' => $vip
        ], '登录成功');
    }

    /**
     * 邮箱登录
     *
     * @param Request $request
     * @return \support\Response
     */
    public function emailLogin(Request $request)
    {
        $defaultType = $request->isWechat() ? BizEnum::TOKEN_TYPE_WECHAT_LOGIN : BizEnum::TOKEN_TYPE_VIP_PC_LOGIN;
        $type = $request->get('type', $defaultType);
        $dto = new LoginFormDto();
        $dto->mode = 'email_code';
        $dto->username = $request->post('username', '');
        $dto->verifyKey = $request->post('key', '');
        $dto->verifyCode = $request->post('code', '');
        $dto->clientType = $type;
        $dto->requestIp = $request->getRealIp();
        list($vip, $token) = $this->getVIPService()->login($dto);
        $filter = new VIPFilter();
        $filter->filter($vip);

        return $this->createSuccessJsonResponse([
            'token' => $token,
            'user' => $vip
        ], '登录成功');
    }

    /**
     *  登录接口
     *
     * @param Request $request
     * @return \support\Response
     */
    public function login(Request $request)
    {
        $defaultType = $request->isWechat() ? BizEnum::TOKEN_TYPE_WECHAT_LOGIN : BizEnum::TOKEN_TYPE_VIP_PC_LOGIN;
        $type = $request->get('type', $defaultType);
        $dto = new LoginFormDto();
        $dto->username = $request->post('username', '');
        $dto->password = $request->post('password', '');
        $dto->clientType = $type;
        $dto->requestIp = $request->getRealIp();
        list($vip, $token) = $this->getVIPService()->login($dto);
        $filter = new VIPFilter();
        $filter->filter($vip);

        return $this->createSuccessJsonResponse([
            'token' => $token,
            'user' => $vip
        ], '登录成功');
    }

    /**
     * 退出登录
     *
     * @param Request $request
     * @return \support\Response
     */
    public function logout(Request $request)
    {
        $vip = $this->getVIPInfo()->toArray();
        $this->getLogService()->info('vip', 'logout', '会员退出', [
            'vip' => $vip,
            'currentIp' => $request->getRealIp()
        ]);
        $this->getVIPService()->destroyToken($vip['loginToken']);
        $this->getBiz()->offsetSet('vip', null);

        return $this->createSuccessJsonResponse(null, '登出成功');
    }
    /**
     * 用户注册
     *
     * @param Request $request
     * @return \support\Response
     */
    public function register(Request $request)
    {
        $fields = $request->post();
        $fields['request_ip'] = $request->getRemoteIp();
        if ($vip = $this->getVIPService()->register($fields)) {
            // TODO: 暂时固定登录，保证h5和pc同步登录
            $defaultType = $request->isWechat() ? BizEnum::TOKEN_TYPE_WECHAT_LOGIN : BizEnum::TOKEN_TYPE_VIP_PC_LOGIN;
            $type = $request->get('type', $defaultType);
            $token = $this->getVIPService()->makeAuthToken($type, $vip['id']);
            $filter = new VIPFilter();
            $filter->filter($vip);

            return $this->createSuccessJsonResponse([
                'token' => $token,
                'user' => $vip
            ], '注册成功');
        }

        return $this->createErrorJsonResponse('注册失败，系统错误');
    }

    /**
     * 发送邮箱登录验证码
     *
     * @param Request $request
     * @return \support\Response
     */
    public function sendEmailLoginCode(Request $request)
    {
        $fields = $request->post();
        v::email()->setName('登录邮箱')->setTemplate('登录邮箱为空或格式错误')->check($fields['username'] ?? '');
        $key = $this->getVIPService()->sendAccountEmailLoginCode($fields['username']);
        if  ($key === null) {
            return $this->createErrorJsonResponse("邮箱登录验证码发送失败");
        }

        return $this->createSuccessJsonResponse([
            'key' => $key
        ]);
    }
}
