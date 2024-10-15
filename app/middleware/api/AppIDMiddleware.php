<?php

namespace app\middleware\api;

use CoreW\Webman\Config;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class AppIDMiddleware implements MiddlewareInterface
{

    public function process(Request $request, callable $handler): Response
    {
       Config::set('app.id', 'api');

       return $handler($request);
    }
}