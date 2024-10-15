<?php


namespace app\middleware\api;


use app\middleware\api\firewall\XAuthTokenAuthenticationListener;

class XAuthTokenIdentityMiddleware  extends \app\middleware\XAuthTokenIdentityMiddleware
{
    protected $currentUserIndex = 'vip';

    public function __construct()
    {
        $this->authenticationListener = new XAuthTokenAuthenticationListener($this->getBiz());
    }
}