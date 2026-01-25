<?php

namespace CoreW\Business\Alarm\Exception;

use CoreW\Exception\AbstractBizException;

class AlarmException extends AbstractBizException
{
    const EXCEPTION_MODULE = 23;

    // 404 - 资源不存在
    const NOTFOUND_ALARM_EVENT = 4042301;
    const NOTFOUND_ALARM_PLAN = 4042302;

    // 400 - 请求参数错误
    const ERROR_PARAMETER = 4002301;
    const ERROR_PARAMETER_MISSING = 4002302;

    // 403 - 权限禁止
    const ALARM_PLAN_DISABLE = 4032301;
    const ALARM_PLAN_ALREADY_BOUND = 4032302;
    const CHANNEL_ALREADY_BOUND = 4032303;
    const CHANNEL_NOT_BOUND = 4032304;

    // 500 - 业务逻辑错误
    const UPDATE_FAILED = 5002301;
    const DELETE_FAILED = 5002302;
    const BIND_FAILED = 5002303;
    const UNBIND_FAILED = 5002304;

    public function __construct($code, $message = null)
    {
        $this->setMessages();
        parent::__construct($code, $message);
    }

    public function setMessages()
    {
        $this->messages = [
            self::NOTFOUND_ALARM_EVENT => '报警事件不存在',
            self::NOTFOUND_ALARM_PLAN => '报警预案不存在',
            self::ERROR_PARAMETER => '参数错误',
            self::ERROR_PARAMETER_MISSING => '缺少必要参数',
            self::ALARM_PLAN_DISABLE => '报警预案已禁用',
            self::ALARM_PLAN_ALREADY_BOUND => '该预案已绑定到此通道',
            self::CHANNEL_ALREADY_BOUND => '通道已绑定到此预案',
            self::CHANNEL_NOT_BOUND => '通道未绑定到此预案',
            self::UPDATE_FAILED => '更新失败',
            self::DELETE_FAILED => '删除失败',
            self::BIND_FAILED => '绑定失败',
            self::UNBIND_FAILED => '解绑失败',
        ];
    }
}
