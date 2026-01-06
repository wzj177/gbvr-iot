<?php


namespace CoreW\Business\Common;


use CoreW\Exception\AbstractBizException;

class UserException extends AbstractBizException
{
    const UN_LOGIN = 4010101;
    const LIMIT_LOGIN = 4010102;
    const NOTFOUND_USER = 4220104;

    const PASSWORD_ERROR = 4220116;
    const PASSWORD_FAILED = 4220117;

    const EXPIRED_OR_NOTFOUND_TOKEN = 4010118;

    const CAPTCHA_ERROR = 4220119;
    const CAPTCHA_EMPTY = 4220120;
    const USERNAME_PASSWORD_ERROR = 4220121;

    const TOKEN_PARAMS_FAILED = 4010219;

    const LOCKED_USER = 4030115;

    const TEMPORARY_LOCKED = 4030141;

    const SYSTEM_USER_NOT_ALLOWED_DELETE = 4030142;

    const SYSTEM_USER_NOT_ALLOWED_MODIFY = 4030143;

    const LOCK_DENIED = 4030140;

    const ROLES_INVALID = 5000135;

    const EMAIL_INVALID = 5000119;

    const EMAIL_EXISTED = 5000120;

    const MOBILE_INVALID = 5000121;

    const MOBILE_EXISTED = 5000122;

    const NICKNAME_INVALID = 5000112;

    const NICKNAME_EXISTED = 5000113;

    const TRUENAME_INVALID = 5000139;

    const PASSWORD_INVALID = 5000123;
    const CLIENT_TYPE_INVALID = 5000124;

    const CREATE_TOKEN_WITH_REDIS_ACQUIRED_LOCK_FAILED = 5000133;

    public function __construct($code)
    {
        $this->setMessages();

        parent::__construct($code);
    }

    public function setMessages()
    {
        $this->messages = [
            self::UN_LOGIN => '用户未登录',
            self::LIMIT_LOGIN => '此帐号已在别处登录，请重新登录',
            self::NOTFOUND_USER => '用户不存在',
            self::PASSWORD_ERROR => '用户名或密码错误',
            self::PASSWORD_FAILED => '用户名或密码错误',
            self::EXPIRED_OR_NOTFOUND_TOKEN => '认证失效或未认证',
            self::LOCKED_USER => '账户被封禁，请联系管理员',
            self::TEMPORARY_LOCKED => '密码错误次数过多，账户已被临时锁定',
            self::SYSTEM_USER_NOT_ALLOWED_DELETE => '系统用户不允许删除',
            self::SYSTEM_USER_NOT_ALLOWED_MODIFY => '系统用户关键信息不允许修改',
            self::ROLES_INVALID => '角色不正确',
            self::EMAIL_INVALID => '邮箱地址格式错误',
            self::EMAIL_EXISTED => '邮箱地址已被注册',
            self::MOBILE_INVALID => '非法的手机号',
            self::MOBILE_EXISTED => '手机号已被注册',
            self::NICKNAME_INVALID => '昵称格式错误',
            self::NICKNAME_EXISTED => '昵称已经存在',
            self::TRUENAME_INVALID => '真实姓名错误',
            self::PASSWORD_INVALID => '密码校验失败',
            self::CAPTCHA_ERROR => '验证码输入错误',
            self::CAPTCHA_EMPTY => '验证码不能为空',
            self::USERNAME_PASSWORD_ERROR => '用户名或密码不能为空',
            self::LOCK_DENIED => '没有封禁该角色的权限',
            self::TOKEN_PARAMS_FAILED => 'token不合法',
            self::CREATE_TOKEN_WITH_REDIS_ACQUIRED_LOCK_FAILED => 'token无法生成：获取锁失败',
            self::CLIENT_TYPE_INVALID => '第三方登录类型不存在',
        ];
    }
}