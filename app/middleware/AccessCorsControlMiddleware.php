<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class AccessCorsControlMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next) : Response
    {
        if (!config('app.debug')) {
            return $next($request);
        }
        if (strtolower($request->method()) === 'options') {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        $response->withHeaders([
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Origin'      => $request->header('origin', '*'),
            'Access-Control-Allow-Methods'     => $request->header('access-control-request-method', '*'),
            'Access-Control-Allow-Headers'     => $request->header('access-control-request-headers', '*'),
            //                'Access-Control-Allow-Origin' => '*',
            //                'Access-Control-Allow-Methods' => 'GET,POST,PUT,DELETE,OPTIONS',
            //                'Access-Control-Allow-Headers' => 'Content-Type,Authorization,X-Requested-With,Accept,Origin,X-Auth-Token',
        ]);
        return $response;
    }

}
