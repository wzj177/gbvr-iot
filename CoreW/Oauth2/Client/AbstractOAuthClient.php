<?php

namespace CoreW\Oauth2\Client;


use Illuminate\Redis\Connections\Connection;
use support\Redis;

abstract class AbstractOAuthClient
{
    protected $config;

    protected $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.3';

    protected $connectTimeout = 30;

    protected $timeout = 30;

    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * 获取Authorization URL
     *
     * @return mixed|null|string|array
     */
    abstract public function getAuthorizationUrl(string $redirect_uri, string $response_type = 'code');

    /**
     * 通过Authorization Code获取Access Token
     * @return mixed|null|string|array
     */
    abstract public function getAccessToken(string $code, string $redirect_uri);

    /**
     * 获取用户信息
     *
     * @param $token
     * @return mixed|null|string|array
     */
    abstract public function getUserInfo($token);

    public function postRequest($url, $params)
    {
        if (isset($this->request)) {
            return $this->request->postRequest($url, $params);
        }

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, 0);
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
        curl_setopt($curl, CURLOPT_URL, $url);
        $response = curl_exec($curl);

        curl_close($curl);

        return $response;
    }

    public function getRequest($url, $params)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_VERBOSE, $this->config['debug'] ?? false);
        curl_setopt($curl, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, 0);

        $url = $url . '?' . http_build_query($params);
        curl_setopt($curl, CURLOPT_URL, $url);

        $response = curl_exec($curl);

        curl_close($curl);

        return $response;
    }

    protected function convertUserInfo($info)
    {
        if (empty($info)) {
            return;
        }

        $userInfo = [];
        $userInfo['id'] = $info['unionid'];
        $userInfo['name'] = $info['nickname'];
        $userInfo['avatar'] = $info['headimgurl'];
        if ($info['sex'] == 1) {
            $userInfo['gender'] = 'male';
        } elseif ($info['sex'] == 2) {
            $userInfo['gender'] = 'female';
        } else {
            $userInfo['gender'] = 'secret';
        }

        return $userInfo;
    }

    /**
     * @return Connection
     */
    protected function cache()
    {
        return Redis::connection('oauth');
    }
}