<?php


namespace app\middleware\admin;


use app\middleware\admin\firewall\XAuthTokenAuthenticationListener;

/**
 * token 校验
 *
 * Class XAuthTokenIdentity
 * @package app\middleware\admin
 */
class XAuthTokenIdentityMiddleware extends \app\middleware\XAuthTokenIdentityMiddleware
{
    public function __construct()
    {
        $this->authenticationListener = new XAuthTokenAuthenticationListener($this->getBiz());
    }
}