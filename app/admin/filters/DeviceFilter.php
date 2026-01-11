<?php

namespace app\admin\filters;

use CoreW\Business\BizEnum;
use CoreW\Business\DataFilters\Filter;
use support\utils\AssetHelper;

class DeviceFilter extends Filter
{
    protected array $publicFields = [];

    protected array $formatFields = [
//        'avatar' => 'url',               // 调用 format_url()
//        'price'  => 'float',             // 调用 format_float()
//        'status' => 'enum:statusToText', // 调用 format_enum() + statusToText()
//        'age'    => 'formatAge',         // 调用自定义 formatAge()
        'last_heartbeat_at' => 'datetime'
    ];
}