<?php

namespace app\middleware\api;

use CoreW\Business\BizEnum;
use CoreW\Business\VIP\CurrentUser;
use CoreW\Business\VIP\Service\VIPService;
use CoreW\Core;
use CoreW\Traits\RequestAndResponseTrait;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

class CompanyIotMiddleware implements MiddlewareInterface
{
    use RequestAndResponseTrait;
    public function process(Request $request, callable $handler): Response
    {
        /** @var $vipInfo CurrentUser */
        $vipInfo = $this->getBiz()->offsetGet('vip');
        if ($vipInfo->getRole() !== BizEnum::VIP_ROLE_COMPANY) {
            return response('<h1>403 forbidden</h1>', 403);
        }

        $iotConfig = $this->getVIPService()->getCompanyIotConfigByUserId((int)$vipInfo->getId());
        if (empty($iotConfig)
            || BizEnum::DISABLED == $iotConfig['status']
            || empty($iotConfig['host'])
            || empty($iotConfig['api'])
        ) {
            return $this->createSuccessJsonResponse([], '暂无数据,请确认物联网配置是否正确');
        }

        return $handler($request);
    }


    /**
     * @return VIPService
     */
    protected function getVIPService()
    {
        return $this->getBiz()->service('VIP:VIPService');
    }

    protected function getBiz()
    {
        return Core::instance();
    }
}