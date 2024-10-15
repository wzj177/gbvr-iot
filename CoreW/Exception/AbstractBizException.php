<?php


namespace CoreW\Exception;



abstract class AbstractBizException extends HttpException
{
    const DEFAULT_STATUS_CODE = 500;

    /**
     * @link  https://blog.csdn.net/ningxinyu520/article/details/18217077
     * @var int[]
     */
    public $statusCodes = [200, 400, 403, 404, 413, 500, 401, 201, 422];

    protected $messages = [];

    public function __construct($code, $message = null)
    {
        $statusCode = substr($code, -strlen($code), 3);
        $statusCode = in_array($statusCode, $this->statusCodes) ? $statusCode : self::DEFAULT_STATUS_CODE;

        if (empty($message)) {
            $message = empty($this->messages[$code]) ? '服务器内部错误' : $this->messages[$code];
        }

        parent::__construct($statusCode, $message, null, [], $code);
    }

    public function getMessages()
    {
        return $this->messages;
    }

    abstract public function setMessages();

    /**
     * @param $method
     * @param $arguments
     * @return HttpExceptionInterface|\Exception
     * @throws \ReflectionException
     */
    public static function __callStatic($method, $arguments)
    {
        $class = get_called_class();
        $code = constant($class.'::'.$method); // 返回一个常量的值
        $reflection = new \ReflectionClass($class);

        return $reflection->newInstance($code);
    }
}