<?php


namespace CoreW\Exception;


use CoreW\Business\Common\CommonBizException;
use Psr\Container\NotFoundExceptionInterface;
use Respect\Validation\Exceptions\ValidationException;

class ExceptionUtil
{
    public static function getErrorAndHttpCodeFromException($exception)
    {
        $error = [
            'code' => $exception->getCode() ?: -1,
            'data' => null,
            'message' => 'Internal server error',
        ];
        if (self::checkIsBusinessException($exception)) {
            $error['message'] = $exception->getMessage();
            $error['code'] = $exception->getCode();
            $httpCode = $exception->getStatusCode();
        } elseif ($exception instanceof NotFoundExceptionInterface || $exception instanceof NotFoundException) {
            $error['message'] = 'Not Found';
            $error['code'] = $exception->getCode() ?: CommonBizException::NOTFOUND_RESOURCE;
            $httpCode = 404;
        } elseif ($exception instanceof BadRequestHttpException || $exception instanceof ValidationException) {
            $error['message'] = $exception->getMessage();
            $error['code'] = CommonBizException::INPUT_PARAMETER_ERROR;
            $httpCode = 400;
        } else {
//            $error['message'] = 'Internal server error';
//            $error['code'] = $exception->getCode() ?: -1;
            $httpCode = 500;
        }

        return [$httpCode, $error];
    }

    private static function checkIsBusinessException($exception)
    {
        if ($exception instanceof HttpExceptionInterface) {
            return true;
        }

        return false;
    }
}