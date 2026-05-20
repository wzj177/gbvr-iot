<?php

namespace CoreW\Business\SystemLog\Exception;


use CoreW\Exception\AbstractBizException;

class SystemLogException extends AbstractBizException
{
    const LOG_NOT_FOUND = 4043501;

    public function __construct($code, $message = null)
    {
        $this->setMessages();
        parent::__construct($code, $code);
    }

    public function setMessages()
    {
        $this->messages = [
            self::LOG_NOT_FOUND => '获取失败，日志不存在',
        ];
    }

}
