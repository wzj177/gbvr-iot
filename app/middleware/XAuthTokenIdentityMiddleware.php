<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class XAuthTokenIdentityMiddleware extends AbstractAuthIdentity implements MiddlewareInterface
{

    public function process(Request $request, callable $next): Response
    {
        try {
            $result = $this->authenticationListener->handle($request);
            if (is_array($result) && isset($result['key']) && $result['key'] === 'Authorization') {
                // jwt auth 续签
//                echo 'jwt auth 续签:', date('Y-m-d H:i:s'), PHP_EOL;
                $this->identity();
                return $next($request)->withHeaders([
                    'Authorization' => $result['token'],
                    'AuthorizationType' => $result['type']
                ]);
            }

            $this->identity();
            return $next($request);

        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
