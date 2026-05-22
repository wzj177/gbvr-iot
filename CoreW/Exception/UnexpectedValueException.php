<?php


namespace CoreW\Exception;


class UnexpectedValueException extends DefaultBusHttpException
{
    public function __construct($message, $code = -1, array $headers = [])
    {
        parent::__construct(500, $message, null, $headers, $code);
    }
}