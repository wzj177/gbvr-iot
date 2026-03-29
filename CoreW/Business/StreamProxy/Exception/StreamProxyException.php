<?php

namespace CoreW\Business\StreamProxy\Exception;

use CoreW\Exception\AbstractBizException;

class StreamProxyException extends AbstractBizException
{
    const EXCEPTION_MODULE = 30;

    // 404 - Not Found
    const PROXY_NOT_FOUND = 4043001;
    const MEDIA_SERVER_NOT_FOUND = 4043002;

    // 400 - Bad Request
    const INVALID_PROXY_TYPE = 4003003;
    const INVALID_PROTOCOL = 4003004;
    const INVALID_SOURCE_URL = 4003005;
    const MISSING_SOURCE_URL = 4003006;
    const INVALID_STATUS = 4003007;

    // 409 - Conflict
    const PROXY_ALREADY_STARTED = 4093008;
    const PROXY_ALREADY_STOPPED = 4093009;
    const PROXY_NOT_RUNNING = 4093010;
    const DUPLICATE_APP_STREAM = 4093011;

    // 500 - Internal Server Error
    const START_FAILED = 5003012;
    const STOP_FAILED = 5003013;
    const ZLM_API_ERROR = 5003014;
    const RECORD_START_FAILED = 5003015;
    const RECORD_STOP_FAILED = 5003016;
    const HEALTH_CHECK_FAILED = 5003017;
    const RECONNECT_FAILED = 5003018;
    const MAX_RETRY_EXCEEDED = 5003019;

    // 403 - Forbidden
    const CANNOT_BIND_PLAN = 4033020;
    const CANNOT_UNBIND_PLAN = 4033021;

    public function __construct($code, ?string $message = null)
    {
        $this->setMessages();
        parent::__construct($code, $message);
    }

    public function setMessages()
    {
        $this->messages = [
            self::PROXY_NOT_FOUND => '流代理不存在',
            self::MEDIA_SERVER_NOT_FOUND => '流媒体服务器不存在',
            self::INVALID_PROXY_TYPE => '无效的代理类型',
            self::INVALID_PROTOCOL => '不支持的协议类型',
            self::INVALID_SOURCE_URL => '无效的源地址',
            self::MISSING_SOURCE_URL => '拉流代理必须提供源地址',
            self::INVALID_STATUS => '无效的状态值',
            self::PROXY_ALREADY_STARTED => '流代理已启动',
            self::PROXY_ALREADY_STOPPED => '流代理已停止',
            self::PROXY_NOT_RUNNING => '流代理未运行',
            self::DUPLICATE_APP_STREAM => '流ID已存在',
            self::START_FAILED => '启动流代理失败',
            self::STOP_FAILED => '停止流代理失败',
            self::ZLM_API_ERROR => 'ZLM API调用失败',
            self::RECORD_START_FAILED => '启动录像失败',
            self::RECORD_STOP_FAILED => '停止录像失败',
            self::HEALTH_CHECK_FAILED => '健康检查失败',
            self::RECONNECT_FAILED => '重连失败',
            self::MAX_RETRY_EXCEEDED => '超过最大重试次数',
            self::CANNOT_BIND_PLAN => '无法绑定录像计划',
            self::CANNOT_UNBIND_PLAN => '无法解绑录像计划',
        ];
    }
}
