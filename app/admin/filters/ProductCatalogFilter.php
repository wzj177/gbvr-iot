<?php


namespace app\admin\filters;


use CoreW\Business\BizEnum;
use CoreW\Business\DataFilters\Filter;
use support\utils\AssetHelper;

class ProductCatalogFilter extends Filter
{
    protected $publicFields = [
        'id',
        'name',
        'path',
        'parentId',
        'icon',
        'cover',
        'coverFull',
        'name',
        'treeName',
        'code',
        'status',
        'statusText',
        'sort',
        'remark',
        'createdTime',
        'recommendTags',
    ];

    public function publicFields(&$data):void
    {
        $data['statusText'] = BizEnum::getEnableOrDisableItems($data['status']);
        if (!empty($data['cover'])) {
            $data['coverFull'] = AssetHelper::getUploadUrl($data['cover'], null, $this->assetUri);
        } else {
            $data['coverFull'] = '';
        }
    }
}