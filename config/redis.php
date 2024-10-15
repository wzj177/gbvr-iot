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
    'default' => [
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 0,
        'prefix' => 'easy_vr_'
    ],
    'admin_token' => [
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 6,
        'prefix' => 'easy_vr_admin_'
    ],
    'vip_token' => [
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 6,
        'prefix' => 'easy_vr_vip_'
    ],
    'oauth' => [
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 6,
        'prefix' => 'easy_vr_oauth_'
    ]
//    'dao-cache' => [
//        'host'     => '127.0.0.1',
//        'password' => null,
//        'port'     => 6379,
//        'database' => 9,
//    ],
];
