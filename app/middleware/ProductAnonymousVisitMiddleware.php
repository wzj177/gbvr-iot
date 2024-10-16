<?php

namespace app\middleware;

use CoreW\Bfw;
use CoreW\Business\BizEnum;
use CoreW\Business\Common\UserException;
use CoreW\Business\Product\Service\ProductService;
use CoreW\Core;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class ProductAnonymousVisitMiddleware implements MiddlewareInterface
{

    public function process(Request $request, callable $handler): Response
    {
        $tokenKey = config('auth.token_handler') === 'jwt' ? 'authorization' : 'x-auth-token';
        $token = $request->header($tokenKey);
        if (!$token) {
            return $handler($request);
        }
        $productCode = $request->header('X-Product-Code', $request->get('prod_code'));
        if (!$productCode) {
            throw  UserException::EXPIRED_OR_NOTFOUND_TOKEN();
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