<?php

namespace app\middleware\api;

use app\middleware\security\firewall\UnifiedAuthenticationListener;
use CoreW\Bfw;
use CoreW\Core;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class AuthIdentityMiddleware extends UnifiedAuthenticationListener implements MiddlewareInterface
{
    public function __construct()
    {
        parent::__construct($this->getBiz());
    }

    public function process(Request $request, callable $next) : Response
    {
        try {
            $this->handle($request);

            $response = $next($request);

            // 如果需要续签JWT token
            if (isset($this->jwtRefreshData)) {
                $response = $response->withHeaders([
                    'Authorization'     => $this->jwtRefreshData['token'],
                    'AuthorizationType' => $this->jwtRefreshData['type'],
                ]);
            }

            return $response;

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