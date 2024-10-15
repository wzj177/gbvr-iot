<?php


namespace app\middleware\admin;


use app\middleware\admin\firewall\BasicAuthenticationListener;

/**
 * basic-auth 认证
 * Class BasicAuthIdentity
 * @package app\middleware\admin
 */
class BasicAuthIdentityMiddleware extends \app\middleware\BasicAuthIdentityMiddleware
{
    public function __construct()
    {
        $this->authenticationListener = new BasicAuthenticationListener($this->getBiz());
    }
}