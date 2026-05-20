<?php


namespace app\middleware;


use CoreW\Bfw;
use CoreW\Core;
use CoreW\RateLimiter\Limiters\LoginSendSmsRateLimiter;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
    protected $routeLimiterMap
        = [
            'auth.config' => 'login_send_sms_rate_limiter',
        ];

    public function process(Request $request, callable $next) : Response
    {
        $routeKey = $request->route->getName();
        if (!isset($this->routeLimiterMap[$routeKey])) {
            return $next($request);
        }

        /** @var $rateLimiter LoginSendSmsRateLimiter */
        $rateLimiter = $this->getBiz()->offsetGet($this->routeLimiterMap[$routeKey]);
        // 参数1：这个ip在1小时内最多调用n次，超过n，则禁止多长时间
        // 参数2：这个ip在当天内最多调用n次，超过n，则禁止多长时间
        $rateLimiter->handle($request->getRealIp(), $request->getRealIp());

        return $next($request);
    }

    /**
     * @return Bfw
     */
    protected function getBiz()
    {
        return Core::instance();
    }
}