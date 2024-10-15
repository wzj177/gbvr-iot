<?php

namespace app\api\filters;

use CoreW\Business\DataFilters\Filter;
use support\utils\AssetHelper;

class ProductSceneFilter extends Filter
{
    protected $publicFields = [
        'id',
        'number',
        'productId',
        'title',
        'panorama',
        'panoramaSize',
        'thumbSize',
        'tileSize',
        'panoramaWidth',
        'panoramaHeight',
        'tileRows',
        'tileColumns',
        'panoramaSmall',
        'thumb',
        'tilePath',
        'tileStatus',
        'longitude',
        'latitude',
        'minFov',
        'maxFov',
        'desc',
        'status',
        'vrOptions',
        'createdTime',
        'tileUrl',
        'panoramaUrl',
        'thumbUrl',
        'panoramaSmallUrl'
    ];

    protected $simpleFields = [
        'name',
        'number',
        'thumb',
        'url',
        'panorama',
        'panoramaFull',
        'panoramaSmall',
        'panoramaSmallUrl',
        'id',
        'uid',
        'sort'
    ];

    protected function publicFields(&$data)
    {
        $data['tileUrl'] = AssetHelper::getUploadUrl($data['tilePath']);
        $data['panoramaUrl'] = AssetHelper::getUploadUrl($data['panorama']);
        $data['thumbUrl'] = AssetHelper::getUploadUrl($data['thumb']);
        $data['panoramaSmallUrl'] = empty($data['panoramaSmall']) ? $data['panoramaUrl'] : AssetHelper::getUploadUrl($data['panoramaSmall']);
    }

    protected function simpleFields(&$data)
    {
        $data['sort'] = $data['number'];
        $data['uid'] = $data['id'];
        $data['url'] = AssetHelper::getUploadUrl($data['thumb']);
        $data['panoramaFull'] = AssetHelper::getUploadUrl($data['panorama']);
        $data['panoramaSmallUrl'] = empty($data['panoramaSmall']) ? $data['panoramaFull'] : AssetHelper::getUploadUrl($data['panoramaSmall']);
    }
}