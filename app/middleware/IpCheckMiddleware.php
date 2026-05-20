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
    protected array $whitePaths
        = [
            '/api/v2/gb/server/hock',
            '/api/v2/zlm/*',
        ];

    public function process(Request $request, callable $next) : Response
    {
        try {
            foreach ($this->whitePaths as $whitePath) {
                if (str_starts_with($request->path(), $whitePath)) {
                    return $next($request);
                }
            }

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
