<?php

namespace app\admin\filters;

use CoreW\Business\DataFilters\Filter;

class CompanyIotConfigFilter extends Filter
{
    protected $publicFields = [
        'id',
        'companyId',
        'appId',
        'appSecret',
        'serviceType',
        'host',
        'status',
        'param',
        'api',
        'createdTime',
    ];
}