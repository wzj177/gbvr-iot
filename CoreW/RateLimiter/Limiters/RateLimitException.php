<?php


namespace CoreW\RateLimiter\Limiters;


use CoreW\Exception\AbstractBizException;

class RateLimitException extends AbstractBizException
{
    const FORBIDDEN_MAX_REQUEST = 4030602;
    const FORBIDDEN_LOGIN_SMS_SEND_HOUR_MAX_REQUEST = 4030603;
    const FORBIDDEN_LOGIN_SMS_SEND_DAY_MAX_REQUEST = 4030604;
    const FORBIDDEN_LOGIN_EMIAL_CODE_SEND_DAY_MAX_REQUEST = 4030605;

    public function __construct($code)
    {
        $this->setMessages();
        parent::__construct($code);
    }

    public function setMessages()
    {
        $this->messages = [
            self::FORBIDDEN_MAX_REQUEST => '请求发送次数过多，请稍后尝试!',
            self::FORBIDDEN_LOGIN_SMS_SEND_HOUR_MAX_REQUEST => '超过短信发送限制，1小时后再尝试!',
            self::FORBIDDEN_LOGIN_SMS_SEND_DAY_MAX_REQUEST => '已超过当日短信发送限制',
            self::FORBIDDEN_LOGIN_EMIAL_CODE_SEND_DAY_MAX_REQUEST => '发送太频繁，1小时后再尝试!'
        ];
    }
}