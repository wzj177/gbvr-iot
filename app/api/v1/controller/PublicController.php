<?php

namespace app\api\v1\controller;

use app\api\BaseController;
use CoreW\Business\BizEnum;
use support\Request;

class PublicController extends BaseController
{
    /**
     * TODO: 需要换成字典表
     * @param Request $request
     * @param string $key
     * @return \support\Response
     */
    public function getDictItems(Request $request, string $key) : \support\Response
    {
        $items = [];
        if ($key === 'vr_product_type') {
            $items = BizEnum::dictToList(BizEnum::getProductTypeItems());
        } else if ($key === 'vr_product_status') {
            $items = BizEnum::dictToList(BizEnum::getProductStatusItems());
        }

        return $this->createSuccessJsonResponse($items);
    }
}