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

use support\Request;

return [
    'name' => env('APP_NAME'),
    'app_id' => env('APP_ID', 'wanjij_easy_gbvr_iot_cloud_2025888'),
    'id' => 'common',
    'debug' => intval(env('APP_DEBUG')),
    'biz_config' => [
        'redis.options' => [
            'host' => env('REDIS_HOST'),
        ],
        'debug' => env('APP_DEBUG'),
        'log_dir' => dirname(__DIR__) . '/runtime/biz/logs',
        'run_dir' => dirname(__DIR__) . '/runtime/biz/run',
        'cache_directory' => dirname(__DIR__) . '/runtime/biz/cache',
        'lock.flock.directory' => dirname(__DIR__) . '/runtime/biz/lock',
    ],
    'error_reporting' => E_ALL,
    'default_timezone' => 'Asia/Shanghai',
    'request_class' => Request::class,
    'public_path' => base_path() . DIRECTORY_SEPARATOR . 'public',
    'runtime_path' => base_path(false) . DIRECTORY_SEPARATOR . 'runtime',
    'controller_suffix' => 'Controller',
    'controller_reuse' => false,
    'design_site_url' => env('DESIGN_SITE_URL'),
    'upload_chunk_tmp_file' => dirname(__DIR__) . '/runtime/upload-chunks', // 上传大文件分片切片目录
    'gb' => [
        'api_hock_secret' => env('API_HOOK_SECRET', 'DADA!@DFSF!@#!@#!@111'),
        'api_hock_allow_ips' => env('API_HOOK_ALLOW_IPS', '127.0.0.1|192.168.1.*'),
    ]
];
