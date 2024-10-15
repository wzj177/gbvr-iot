<?php

namespace app\middleware;

use app\middleware\security\firewall\IPCheck;
use CoreW\Bfw;
use CoreW\Core;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class IpCheckMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        try {
            $check = new IPCheck($this->getBiz());
            $check->validate($request);

            return $next($request);
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * @return Bfw
     */
    protected function getBiz()
    {
        return Core::instance();
    }
}
