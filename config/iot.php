<?php

use CoreW\Sdk\Iot\Driver\BytV3;
use CoreW\Sdk\Iot\Driver\BytV4;
use CoreW\Sdk\Iot\Driver\Common;

return [
    //    'common' => [
    //        'name' => '通用',
    //        'driver' => Common::class,
    //        'apiMap' => []
    //    ],
    'byt_v4' => [
        'name'   => '乡亿客物联网V4',
        'driver' => BytV4::class,
        'apiMap' => [
            [
                'funCode' => 'device_catalog',
                'title'   => '设备分类',
                'url'     => '/vr/device/catalogs',
                'method'  => 'GET',
                'param'   => '',
                'keyMap'  => json_encode([
                    'name' => 'title',
                    'code' => 'id',
                ]),
            ],
            [
                "funCode" => "device_list",
                "title"   => "设备列表",
                "url"     => "/vr/devices/{id}",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
            [
                "funCode" => "device_info",
                "title"   => "设备信息",
                "url"     => "/vr/device/{id}",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
            [
                "funCode" => "device_real_data",
                "title"   => "设备实时数据",
                "url"     => "/vr/device/{id}/real",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
            [
                "funCode" => "device_history_data",
                "title"   => "设备历史数据",
                "url"     => "/vr/device/{id}/history",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
            [
                "funCode" => "camera_live_url",
                "title"   => "摄像头直播地址",
                "url"     => "/vr/camera/{id}/live",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
            [
                "funCode" => "gis_tiles_url",
                "title"   => "GIS切片地址(基地鸟瞰图等)",
                "url"     => "vr/gis/tiles",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
        ],
    ],
    'byt_v3' => [
        'name'   => '乡亿客物联网V3',
        'driver' => BytV3::class,
        'apiMap' => [
            [
                'funCode' => 'device_catalog',
                'title'   => '设备分类',
                'url'     => '/vr/device/catalogs',
                'method'  => 'GET',
                'param'   => '',
                'keyMap'  => '',
            ],
            [
                "funCode" => "device_list",
                "title"   => "设备列表",
                "url"     => "/vr/devices/{id}",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
            [
                "funCode" => "device_info",
                "title"   => "设备信息",
                "url"     => "/vr/device/{id}",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
            [
                "funCode" => "device_real_data",
                "title"   => "设备实时数据",
                "url"     => "/vr/device/{id}/real",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
            [
                "funCode" => "device_history_data",
                "title"   => "设备历史数据",
                "url"     => "/vr/device/{id}/history",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
            [
                "funCode" => "camera_live_url",
                "title"   => "摄像头直播地址",
                "url"     => "/vr/camera/{id}/live",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
            [
                "funCode" => "gis_tiles_url",
                "title"   => "GIS切片地址(基地鸟瞰图等)",
                "url"     => "vr/gis/tiles",
                "method"  => "GET",
                "param"   => "",
                'keyMap'  => '',
            ],
        ],
    ],
];