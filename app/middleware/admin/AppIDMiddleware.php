<?php

namespace app\middleware\admin;

use CoreW\Webman\Config;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class AppIDMiddleware implements MiddlewareInterface
{

    public function process(Request $request, callable $handler): Response
    {
        Config::set('app.id', 'admin');

        return $handler($request);
    }
}