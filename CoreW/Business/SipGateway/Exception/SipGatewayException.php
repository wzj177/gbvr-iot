<?php

namespace CoreW\Business\SipGateway\Exception;

use CoreW\Exception\AbstractBizException;

class SipGatewayException extends AbstractBizException
{
    const EXCEPTION_MODULE = 31;

    // 404 - Not Found
    const GATEWAY_NOT_FOUND = 4043101;

    // 400 - Bad Request
    const INVALID_PARAMETER = 4003102;
    const DUPLICATE_GATEWAY_ID = 4003103;
    const DUPLICATE_HOST_PORT = 4003104;
    const GATEWAY_HAS_DEVICES = 4003105;

    // 403 - Forbidden
    const GATEWAY_DISABLED = 4033106;

    // 500 - Internal Server Error
    const HEARTBEAT_FAILED = 5003107;

    public function __construct($code, ?string $message = null)
    {
        $this->setMessages();
        parent::__construct($code, $message);
    }

    public function setMessages()
    {
        $this->messages = [
            self::GATEWAY_NOT_FOUND => 'SIP网关不存在',
            self::INVALID_PARAMETER => '参数错误',
            self::DUPLICATE_GATEWAY_ID => '网关标识已存在',
            self::DUPLICATE_HOST_PORT => 'SIP监听地址和端口已存在',
            self::GATEWAY_HAS_DEVICES => '网关下存在关联设备，无法删除',
            self::GATEWAY_DISABLED => '网关已被禁用',
            self::HEARTBEAT_FAILED => '心跳上报失败',
        ];
    }
}
