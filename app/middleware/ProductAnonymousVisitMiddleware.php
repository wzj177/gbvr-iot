<?php

namespace app\middleware;

use app\middleware\api\firewall\XAuthTokenAuthenticationListener;
use CoreW\Bfw;
use CoreW\Business\BizEnum;
use CoreW\Business\VIP\Exception\VIPException;
use CoreW\Business\Product\Service\ProductService;
use CoreW\Core;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class ProductAnonymousVisitMiddleware extends AbstractAuthIdentity implements MiddlewareInterface
{
    protected $currentUserIndex = 'vip';

    public function process(Request $request, callable $handler): Response
    {
        $tokenKey = config('auth.token_handler') === 'jwt' ? 'authorization' : 'x-auth-token';
        $token = $request->header($tokenKey);
        if (!empty($token)) {
            // TODO: 验证token
            try {
                $authenticationListener = new XAuthTokenAuthenticationListener($this->getBiz());
                $result = $authenticationListener->handle($request);
                if (is_array($result) && isset($result['key']) && $result['key'] === 'Authorization') {
                    $this->identity();
                    return $handler($request)->withHeaders([
                        'Authorization' => $result['token'],
                        'AuthorizationType' => $result['type']
                    ]);
                }
                return $handler($request);
            } catch (\Throwable $e) {
                throw $e;
            }
        }
        $productCode = $request->header('X-Product-Code', $request->get('prod_code'));
        if (!$productCode) {
            throw  VIPException::EXPIRED_OR_NOTFOUND_TOKEN();
        }

        $product = $this->getProductService()->getProductByCode($productCode);
        if (!$product || $product['status'] !== BizEnum::PRODUCT_STATUS_PUBLISHED || 1 != $product['anonymousShow']) {
            throw UserException::EXPIRED_OR_NOTFOUND_TOKEN();
        }

        return $handler($request);
    }

    /**
     * @return ProductService
     */
    protected function getProductService(): ProductService
    {
        return $this->getBiz()->service('Product:ProductService');
    }

    /**
     * @return Bfw
     */
    protected function getBiz(): Bfw
    {
        return Core::instance();
    }
}