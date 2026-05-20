<?php

namespace CoreW\Business\VIP\Exception;

use CoreW\Exception\AbstractBizException;

class VIPException extends \CoreW\Business\Common\UserException
{
    const REGISTER_VIP_ERROR = 4133401;
    const EMAIL_VERIFY_LINK_INVALID = 4003401;
    const LOGIN_SEND_EMAIL_CODE_NOTFOUND_USER = 4220106;
    const LOGIN_EMAIL_CODE_EMPTY = 4220107;
    const LOGIN_EMAIL_CODE_ERROR = 4220108;
    const LOGIN_EMAIL_CODE_EXPIRED = 4220109;
    const LOGIN_EMAIL_CODE_USERNAME_ERROR = 4220110;
    const LOGIN_OAUTH2_CODE_NULL_ERROR = 4220111;
    const LOGIN_OAUTH2_REDIRECT_URI_NULL_ERROR = 4220112;
    const LOGIN_OAUTH2_ERROR = 4220113;

    const OK_APPLY_COMPANY = 4133402;
    const ALREADY_APPLY_COMPANY = 4133403;
    const COMPANY_CODE_EXIST = 4133404;

    const COMPANY_NAME_EXIST = 4133405;

    const NOTFOUND_VIP_COMPANY = 4223406;

    const NOT_VIP_COMPANY_USER = 4033407;


    public function setMessages()
    {
        parent::setMessages();
        $this->messages += [
            self::REGISTER_VIP_ERROR                   => '会员注册失败',
            self::EMAIL_VERIFY_LINK_INVALID            => '邮箱验证链接失效',
            self::LOGIN_SEND_EMAIL_CODE_NOTFOUND_USER  => '获取验证码失败，该邮箱未注册',
            self::LOGIN_EMAIL_CODE_EMPTY               => '邮箱登录验证码为空',
            self::LOGIN_EMAIL_CODE_ERROR               => '邮箱登录验证码不正确',
            self::LOGIN_EMAIL_CODE_USERNAME_ERROR      => '登录邮箱格式错误',
            self::LOGIN_EMAIL_CODE_EXPIRED             => '邮箱登录验证码已过期，请重新发送',
            self::LOGIN_OAUTH2_CODE_NULL_ERROR         => '第三方授权登录失败，授权code未提供',
            self::LOGIN_OAUTH2_REDIRECT_URI_NULL_ERROR => '第三方授权登录失败，授权回调地址未提供',
            self::LOGIN_OAUTH2_ERROR                   => '第三方授权登录失败, 未获取到认证信息',
            self::OK_APPLY_COMPANY                     => '您已经是企业会员,无须再申请认证',
            self::ALREADY_APPLY_COMPANY                => '您的企业认证已提交,请耐心等待审核',
            self::COMPANY_CODE_EXIST                   => '企业信用代码已存在',
            self::COMPANY_NAME_EXIST                   => '企业名称已存在',
            self::NOTFOUND_VIP_COMPANY                 => '企业信息不存在',
            self::NOT_VIP_COMPANY_USER                 => '无权限修改他人企业信息',
        ];
    }
}
