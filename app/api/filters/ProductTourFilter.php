<?php

namespace app\api\filters;

use CoreW\Business\DataFilters\Filter;
use support\utils\AssetHelper;

class ProductTourFilter extends Filter
{
    protected $publicFields
        = [
            'id',
            'productId',
            'title',
            'startImg',
            'endImg',
            'loopPlay',
            'endToStart',
            'txtPosition',
            'txtSize',
            'createdTime',
        ];

    protected function publicFields(&$data)
    {
        $data['startImgFull'] = AssetHelper::getUploadUrl($data['startImg']);
        $data['endImgFull'] = AssetHelper::getUploadUrl($data['endImg']);
    }
}