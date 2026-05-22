<?php

namespace CoreW\Business\Role\Exception;

use CoreW\Exception\AbstractBizException;

class RoleException extends AbstractBizException
{
    const CODE_NOT_ALLL_DIGITAL = 4030320;
    const FORBIDDEN_MODIFY = 4030321;

    public function __construct($code)
    {
        $this->setMessages();
        parent::__construct($code);
    }

    public function setMessages()
    {
        $this->messages = [
            self::CODE_NOT_ALLL_DIGITAL => '角色代码不能全是数字，必须包含字母！',
            self::FORBIDDEN_MODIFY      => '系统角色禁止修改！',
        ];
    }

}
