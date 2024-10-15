<?php
namespace app\middleware\api;

use CoreW\Business\Product\Service\ProductService;
use CoreW\Core;
use support\utils\StringToolkit;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

class ProductVisibleMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next) : Response
    {
        $authorization = $request->header('authorization', '');
        list($admin, $password) = StringToolkit::parseBasicAuthData(str_replace('Basic ', '', $authorization));
        $this->getProductService()->validateViewPwd($admin, $password);

        return $next($request);
    }

    /**
     * @return ProductService
     */
    protected function getProductService()
    {
        return Core::instance()->service('Product:ProductService');
    }
}
