<?php

namespace CoreW\Oauth2\Client;

class WechatMobOauthClient extends AbstractOAuthClient
{
    const USERINFO_URL = 'https://api.weixin.qq.com/sns/userinfo';
    const AUTHORIZE_URL = 'https://open.weixin.qq.com/connect/oauth2/authorize?';
    const OAUTH_TOKEN_URL = 'https://api.weixin.qq.com/sns/oauth2/access_token';

    public function getAuthorizationUrl(string $redirect_uri, string $response_type = 'code')
    {
        $params = array();
        $params['appid'] = $this->config['key'];
        $params['redirect_uri'] = $redirect_uri;
        $params['response_type'] = $response_type;
        $params['scope'] = 'snsapi_userinfo';

        return self::AUTHORIZE_URL . http_build_query($params) . '#wechat_redirect';
    }

    public function getAccessToken(string $code, string $redirect_uri)
    {
        $params = array(
            'appid' => $this->config['key'],
            'secret' => $this->config['secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
        );
        $result = $this->getRequest(self::OAUTH_TOKEN_URL, $params);
        $rawToken = json_decode($result, true);
        $userInfo = $this->getUserInfo($rawToken);

        return [
            'userId' => $userInfo['id'],
            'expiredTime' => $rawToken['expires_in'],
            'access_token' => $rawToken['access_token'],
            'token' => $rawToken['access_token'],
            'openid' => $rawToken['openid'],
        ];
    }

    public function getUserInfo($token)
    {
        $params = [
            'openid' => $token['openid'],
            'access_token' => $token['access_token'],
            'lang' => 'zh_CN'
        ];
        $result = $this->getRequest(self::USERINFO_URL, $params);
        $info = json_decode($result, true);

        return $this->convertUserInfo($info);
    }
}