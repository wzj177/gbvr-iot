<?php


namespace app\admin\filters;


use CoreW\Business\BizEnum;
use CoreW\Business\DataFilters\Filter;

class ProductTagFilter extends Filter
{
    protected $publicFields = [
        'id',
        'name',
        'type',
        'typeTxt',
        'createdTime',
    ];

    public function publicFields(&$data):void
    {
        $data['typeTxt'] = BizEnum::getProductTagTypeItems($data['type']);
    }
}