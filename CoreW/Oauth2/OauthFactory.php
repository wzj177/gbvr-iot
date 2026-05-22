<?php

namespace CoreW\Oauth2;

use CoreW\Business\Common\CommonBizException;
use CoreW\Oauth2\Client\AbstractOAuthClient;
use CoreW\Oauth2\Client\QQOauthClient;
use CoreW\Oauth2\Client\WechatMobOauthClient;
use CoreW\Oauth2\Client\WechatWebOAuthClient;

class OauthFactory
{
    const CLIENTS
        = [
            'qq'         => [
                'name'                 => 'QQ帐号',
                'admin_name'           => 'QQ登录接口',
                'class'                => QQOauthClient::class,
                'key_setting_label'    => 'App ID',
                'secret_setting_label' => 'App Key',
                'apply_url'            => 'http://connect.qq.com/',
            ],
            'wechat_web' => [
                'name'                 => '微信网页登录接口',
                'admin_name'           => '微信网页登录接口',
                'class'                => WechatWebOAuthClient::class,
                'key_setting_label'    => 'App ID',
                'secret_setting_label' => 'App Secret',
                'apply_url'            => 'https://open.weixin.qq.com/cgi-bin/frame?t=home/web_tmpl&lang=zh_CN',
            ],
            'wechat_mob' => [
                'name'                    => '微信内分享登录接口',
                'admin_name'              => '微信内分享登录接口',
                'class'                   => WechatMobOauthClient::class,
                'key_setting_label'       => 'App ID',
                'secret_setting_label'    => 'App Secret',
                'mp_secret_setting_label' => 'MP文件验证码',
                'apply_url'               => 'https://mp.weixin.qq.com/cgi-bin/readtemplate?t=register/step1_tmpl&lang=zh_CN',
            ],
        ];

    /***
     * @param string $type
     * @param array $config
     * @return AbstractOAuthClient
     */
    public static function create(string $type, array $config)
    {
        if (!isset(self::CLIENTS[$type])) {
            throw CommonBizException::ERROR_PARAMETER();
        }

        if (empty($config['key']) || empty($config['secret'])) {
            throw CommonBizException::ERROR_PARAMETER_MISSING();
        }

        $class = self::CLIENTS[$type]['class'];

        return new $class($config);
    }
}