<?php


namespace CoreW\Gii;


use Throwable;

class GiiException extends \Exception
{
    const CODE_SERVICE_EXISTED = 101;
    const CODE_BIZ_TEMPLATE_EXIST = 103;
    const CODE_PATH_NOT_OPEN = 105;
    const CODE_CLEAR_SERVICE_FAILED = 107;

    protected $serviceFilePath = null;

    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function serviceExisted($serviceFilePath, $message = "Biz Service Existed")
    {
        $exception = new self($message, self::CODE_SERVICE_EXISTED);
        $exception->serviceFilePath = $serviceFilePath;

        return $exception;
    }

    public static function bizTemplateNotExist($message = "Biz Code Template Not Exist")
    {
        return new self($message, self::CODE_BIZ_TEMPLATE_EXIST);
    }

    public static function pathNotOpen($message = "Make Biz Not Open")
    {
        return new self($message, self::CODE_PATH_NOT_OPEN);
    }

    public static function clearServiceFilesFailed($message = "Clear Already Service Files Error")
    {
        return new self($message, self::CODE_CLEAR_SERVICE_FAILED);
    }

    public function getIsServiceExisted()
    {
        return $this->code === self::CODE_SERVICE_EXISTED;
    }

    public function getIsBizTemplateExist()
    {
        return $this->code === self::CODE_BIZ_TEMPLATE_EXIST;
    }

    public function getIsPathNotOpen()
    {
        return $this->code === self::CODE_PATH_NOT_OPEN;
    }

    public function getIsClearServiceFilesFailed()
    {
        return $this->code === self::CODE_CLEAR_SERVICE_FAILED;
    }

    /**
     * @return string|null
     */
    public function getServiceFilePath()
    {
        return $this->serviceFilePath;
    }
}
