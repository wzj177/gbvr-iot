<?php


namespace CoreW\Business\Auth;


use CoreW\Business\Auth\Handler\DefaultTokenHandler;
use CoreW\Business\Auth\Handler\JwtTokenHandler;
use CoreW\Business\Auth\Handler\TokenHandlerInterface;
use CoreW\Business\User\Service\TokenService;
use CoreW\Exception\UnexpectedValueException;

class AuthFactory
{
    private static $handlerMap
        = [
            'default' => DefaultTokenHandler::class,
            'jwt'     => JwtTokenHandler::class,
        ];

    /**
     * @param $handler
     * @param $biz
     * @param $tokenService
     * @return TokenHandlerInterface
     */
    public static function auth($handler, $biz, TokenService $tokenService)
    {
        if (!isset(self::$handlerMap[$handler])) {
            throw new UnexpectedValueException('The handler is not defined');
        }

        return new self::$handlerMap[$handler]($biz, $tokenService);
    }
}