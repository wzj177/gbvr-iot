<?php

namespace app\middleware;

use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class GBHock implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        $apiHookSecret = config('app.gb.api_hock_secret');
        $serverDomain = config('gb28181.server_domain');
        $allowIps = explode('|', config('gb28181.api_hock_allow_ips', '127.0.0.1'));
        $clientIp = $request->getRealIp();
        // 检查客户端IP是否在允许列表中
        if (!$this->checkAllowedIp($clientIp, $allowIps)) {
            var_dump(1);
            return response('IP Not Allowed', 403);
        }

        // 从请求头获取token
        $token = $request->header('X-Token', '');
        // 如果没有token或者token为空，则返回401错误
        if (empty($token)) {
            return response('Unauthorized', 401);
        }

        // 验证token
        if (!password_verify($apiHookSecret . $serverDomain, $token)) {
            return response('Invalid token', 401);
        }

        return $next($request);
    }

    public function checkAllowedIp(string $clientIp, array $allowIps): bool
    {
        // 获取配置信息
        $isAllowedIp = false;
        foreach ($allowIps as $allowedIp) {
            if ($this->ipMatch($clientIp, $allowedIp)) {
                $isAllowedIp = true;
                break;
            }
        }

        return $isAllowedIp;
    }

    /**
     * 检查IP是否匹配，支持通配符*
     *
     * @param string $ip 客户端IP
     * @param string $pattern IP模式，支持通配符*
     * @return bool 是否匹配
     */
    private function ipMatch(string $ip, string $pattern): bool
    {
        // 如果没有通配符，直接比较
        if (!str_contains($pattern, '*')) {
            return $ip === $pattern;
        }

        // 将模式中的点号转义，并将*替换为正则表达式
        $regex = str_replace('\*', '[0-9]{1,3}', preg_quote($pattern, '/'));

        // 使用正则表达式匹配IP
        return preg_match('/^' . $regex . '$/', $ip) === 1;
    }
}