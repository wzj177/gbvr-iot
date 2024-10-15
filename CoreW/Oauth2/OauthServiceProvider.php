<?php

namespace CoreW\Oauth2;

use Pimple\Container;
use Pimple\ServiceProviderInterface;

class OauthServiceProvider implements ServiceProviderInterface
{
    public function register(Container $app)
    {
        $app['oauth2_qq'] = function () use ($app) {
            $config = config('auth.oauth2.qq');
            if (!isset($config['debug'])) {
                $config['debug'] = config('app.debug');
            }

            return OauthFactory::create('qq', $config);
        };

        $app['oauth2_wechat_web'] = function () use ($app) {
            $config = config('oauth2.wechat_web');
            if (!isset($config['debug'])) {
                $config['debug'] = config('app.debug');
            }

            return OauthFactory::create('wechat_web', $config);
        };

        $app['oauth2_wechat_mob'] = function () use ($app) {
            $config = config('oauth2.wechat_mob');
            if (!isset($config['debug'])) {
                $config['debug'] = config('app.debug');
            }

            return OauthFactory::create('wechat_mob', $config);
        };
    }
}