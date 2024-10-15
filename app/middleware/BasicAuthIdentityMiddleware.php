<?php

namespace app\middleware;

use app\middleware\admin\firewall\BasicAuthenticationListener;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class BasicAuthIdentityMiddleware extends AbstractAuthIdentity implements MiddlewareInterface
{

    public function process(Request $request, callable $next): Response
    {
        $authorization = $request->header('authorization', null);
        if ($authorization
            && false !== strpos(strtolower($authorization), 'basic')
            && null === $request->header('x-auth-token', null)) {
            $this->authenticationListener->handle($request);
            $this->identity();
        }

        return $next($request);
    }

}
