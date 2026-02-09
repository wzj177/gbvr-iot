<?php


namespace CoreW\Business\Common;


use CoreW\Exception\AbstractBizException;

class CommonBizException extends AbstractBizException
{
    const EXCEPTION_MODULE = 03;

    const FORBIDDEN_DRAG_CAPTCHA_ERROR = 4030301;

    const FORBIDDEN_DRAG_CAPTCHA_EXPIRED = 4030302;

    const FORBIDDEN_DRAG_CAPTCHA_REQUIRED = 4030303;

    const FORBIDDEN_FREQUENT_OPERATION = 4030304;

    const ERROR_PARAMETER_MISSING = 5000305;

    const ERROR_PARAMETER = 5000306;

    const ERROR_PARAMETER_DUPLICATE = 5000310;

    const FORBIDDEN_DRAG_CAPTCHA_FREQUENT = 5000307;

    const NOTFOUND_METHOD = 4040308;

    const PLUGIN_IS_NOT_INSTALL = 4040309;

    const NOTFOUND_SERVICE_PROVIDER = 4040310;

    const NOT_ALLOWED_METHOD = 4030311;

    const EXPIRED_UPLOAD_TOKEN = 5000312;

    const NOTFOUND_API = 4040313;

    const NOTFOUND_RESOURCE = 4040001;

    const UPGRADE_V2_ERROR = 4030314;

    const SWITCH_OLD_VERSION_PERMISSION_ERROR = 4030315;

    const SWITCH_OLD_VERSION_ERROR = 4030316;

    const FIELDS_FORMAT_ERROR = 500317;

    const USER_IP_FORBIDDEN = 4030001;

    const NO_USER_DATA_FORBIDDEN = 4030002;
    const RESOURCE_FORBIDDEN = 4030003;

    const INPUT_PARAMETER_ERROR = 4000001;
    const MISSING_NECESSARY_PARAMETERS = 4000002;
    const PARAMETER_TYPE_ERROR = 4000003;
    const PARAMETER_FORMAT_ERROR = 4000004;

    public function __construct($code, ?string $message = null)
    {
        $this->setMessages();

        parent::__construct($code, $message);
    }


    public function setMessages()
    {
        $this->messages = [
            self::FORBIDDEN_FREQUENT_OPERATION => '操作过于频繁，请稍后再试！',
            self::ERROR_PARAMETER_MISSING => '参数缺失，请重试！',
            self::ERROR_PARAMETER => '参数错误，请重试！',
            self::ERROR_PARAMETER_DUPLICATE => '数据已存在，请勿重复操作！',
            self::NOTFOUND_METHOD => '方法不存在',
            self::NOT_ALLOWED_METHOD => '不允许的方法',
            self::NOTFOUND_API => '不允许的方法',
            self::NOTFOUND_RESOURCE => '资源不存在',
            self::FIELDS_FORMAT_ERROR => '字段格式错误',
            self::USER_IP_FORBIDDEN => 'ip被封，无权访问',
            self::INPUT_PARAMETER_ERROR => '参数值不在合法范围内',
            self::MISSING_NECESSARY_PARAMETERS => '缺少必要参数',
            self::PARAMETER_TYPE_ERROR => '参数类型错误',
            self::PARAMETER_FORMAT_ERROR => '参数格式错误',
            self::NO_USER_DATA_FORBIDDEN => '无该数据操作权限',
            self::RESOURCE_FORBIDDEN => '无权限访问该资源'
        ];
    }
}