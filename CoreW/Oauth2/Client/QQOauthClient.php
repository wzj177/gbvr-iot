<?php

namespace CoreW\Oauth2\Client;

/**
 * 流程见：@link  https://wiki.connect.qq.com/%e5%bc%80%e5%8f%91%e6%94%bb%e7%95%a5_server-side
 */
class QQOauthClient extends AbstractOAuthClient
{
    const USERINFO_URL = 'https://graph.qq.com/user/get_user_info';
    const AUTHORIZE_URL = 'https://graph.qq.com/oauth2.0/authorize?';
    const OAUTH_TOKEN_URL = 'https://graph.qq.com/oauth2.0/token';

    const OAUTH_ME_URL = 'https://graph.qq.com/oauth2.0/me';

    public function getAuthorizationUrl(string $redirect_uri, string $response_type = 'code')
    {
        $params = [
            'response_type' => $response_type,
            'client_id'     => $this->config['key'],
            'redirect_uri'  => $redirect_uri,
            'state'         => 'wanzi',
        ];

        return self::AUTHORIZE_URL . http_build_query($params);
    }

    public function getAccessToken(string $code, string $redirect_uri)
    {
        $params = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $this->config['key'],
            'redirect_uri'  => $redirect_uri,
            'client_secret' => $this->config['secret'],
            'code'          => $code,
        ];

        $jsonResult = $this->cache()->get($this->config['key']);
        if (empty($jsonResult)) {
            $result = $this->getRequest(self::OAUTH_TOKEN_URL, $params);
            $jsonResult = $this->formatResultToJson($result);
            if ($jsonResult['error'] !== 0) {
                throw new Oauth2Exception("oauth2接口请求失败，{$jsonResult['error_description']}");
            }

            $this->cache()->setex($this->config['key'], $jsonResult['expires_in'] - 3600, serialize($jsonResult));
        } else {
            $jsonResult = unserialize($jsonResult);
        }

        $userInfo = $this->getUserInfo($jsonResult);

        return [
            'userInfo'    => $userInfo,
            'expiredTime' => $jsonResult['expires_in'],
            'accessToken' => $jsonResult['access_token'],
            'token'       => $jsonResult['access_token'],
        ];
    }

    public function getUserInfo($token)
    {
        $params = ['access_token' => $token['access_token']];
        $result = $this->getRequest(self::OAUTH_ME_URL, $params);
        if (false !== strpos($result, 'callback')) {
            $lpos = strpos($result, '(');
            $rpos = strrpos($result, ')');
            $result = substr($result, $lpos + 1, $rpos - $lpos - 1);
        }

        $user = json_decode($result);
        $token['id'] = $user->openid;
        $params = [
            'oauth_consumer_key' => $token['key'] ?? $this->config['key'], // 因为移动端第三方登录会走此接口，移动端的key和网站的key是不一样的
            'openid'             => $token['id'],
            'format'             => 'json',
            'access_token'       => $token['access_token'],
        ];
        $result = $this->getRequest(self::USERINFO_URL, $params);
        $info = json_decode($result, true);
        $info['id'] = $token['id'];

        return $this->convertUserInfo($info);
    }

    protected function convertUserInfo($info)
    {
        $userInfo = [];
        $userInfo['id'] = $info['id'];
        $userInfo['name'] = $info['nickname'];
        $userInfo['avatar'] = empty($info['figureurl_qq_2']) ? $info['figureurl_qq_1'] : $info['figureurl_qq_2'];
        if ('男' == $info['gender']) {
            $userInfo['gender'] = 'male';
        } else if ('女' == $info['gender']) {
            $userInfo['gender'] = 'female';
        } else {
            $userInfo['gender'] = 'secret';
        }

        return $userInfo;
    }

    /**
     * @param $result
     * @return array|bool|null
     */
    protected function formatResultToJson($result)
    {
        $result = str_replace([
            'callback( ',
            " );\n",
        ], '', $result);
        $json = json_decode($result, true);
        if ($json === null) {
            parse_str($result, $json);
            if (!empty($json['access_token'])) {
                $json['error'] = 0;
                return $json;
            }
        }

        return $json ? $json : ["error" => -1, "error_description" => '未知错误'];
    }
}