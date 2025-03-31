<?php


namespace CoreW\Business\VIP\Dao;


class LoginFormDto
{
    public $mode = 'default';

    public $userId;

    public  $username;

    public  $password;

    public $rememberMe = false;

    public $requestIp;

    public $verifyKey;
    public $verifyCode;

    public $clientType;

    public $oauth2Code;
    public $oauth2State = 'wanzi';
    public $oauth2RedirectUri;


}