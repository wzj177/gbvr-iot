<?php


namespace CoreW\Sdk\AmapSdk\Libs;


class ClientException extends \Exception
{
    protected array $transferInfo;

    /**
     * Undocumented function
     *
     * @param string $message
     * @param int $code
     * @param array $transferInfo 传输
     */
    public function __construct($message = '', $code = 0, array $transferInfo = [])
    {
        parent::__construct($message, $code);
        $this->transferInfo = $transferInfo;
    }
}