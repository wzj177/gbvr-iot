<?php

namespace app\api\filters;

use CoreW\Business\DataFilters\Filter;
use support\utils\AssetHelper;

class VIPCompanyFilter extends Filter
{
    protected $simpleFields
        = [
            'id',
            'userId',
            'name',
            'code',
            'logo',
            'logoFull',
            'address',
            'contactName',
            'contactMobile',
            'contactEmail',
            'status',
            'reason',
            'license',
            'licenseFull',
            'createdTime',
            'createdTime',
        ];

    protected $publicFields
        = [
            'id',
            'name',
            'status',
            'code',
            'logo',
            'logoFull',
            'license',
            'licenseFull',
        ];

    protected function simpleFields(&$data)
    {
        $this->transformImage($data);
    }

    protected function publicFields(&$data)
    {

    }


    private function transformImage(&$data)
    {
        $data['logoFull'] = AssetHelper::getUploadUrl($data['logo']);
        $data['licenseFull'] = AssetHelper::getUploadUrl($data['license']);
    }
}