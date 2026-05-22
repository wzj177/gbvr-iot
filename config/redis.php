<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

return [
    'default'     => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port'     => env('REDIS_PORT', 6379),
        'prefix'   => 'gbvr_iot_',
    ],
    'admin_token' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port'     => env('REDIS_PORT', 6379),
        'database' => 14,
        'prefix'   => 'gbvr_iot_admin_',
    ],
    'vip_token'   => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port'     => env('REDIS_PORT', 6379),
        'database' => 13,
        'prefix'   => 'gbvr_iot_vip_',
    ],
    'oauth'       => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port'     => env('REDIS_PORT', 6379),
        'database' => 12,
        'prefix'   => 'gbvr_iot_oauth_',
    ],
    'gb_gateway'  => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port'     => env('REDIS_PORT', 6379),
        'database' => 11,
        'prefix'   => 'gbvr_iot_gb_gateway_',
    ],
    'api_cache'   => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port'     => env('REDIS_PORT', 6379),
        'database' => 1,
        'prefix'   => 'gbvr_iot_api_cache_',
    ],
    'sdk_cache'   => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port'     => env('REDIS_PORT', 6379),
        'database' => 2,
        'prefix'   => 'gbvr_iot_sdk_cache_',
    ],
    //    'dao-cache' => [
    //        'host'     => '127.0.0.1',
    //        'password' => null,
    //        'port'     => 6379,
    //        'database' => 9,
    //    ],
];
