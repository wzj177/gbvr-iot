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
    'name' => getenv('APP_NAME'),
    'id' => 'common',
    'debug' => getenv('APP_DEBUG'),
    'biz_config' => [
        'redis.options' => [
            'host' => getenv('REDIS_HOST'),
        ],
        'debug' => getenv('APP_DEBUG'),
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
    'token_storage' => env('TOKEN_STORAGE', 'db'),
    'admin' => [
        'auth_ttl' => 3600 * 24 * 7, // 默认token登录过期时间：7天
        'jwt_ttl' => '', // access token 过期时间，如果设置则会覆盖默认的jwt配置
        'jwt_refresh_ttl' => '',// refresh token 过期时间，如果设置则会覆盖默认的jwt配置
        'no_required_auth_routes' => getenv('ADMIN_NO_REQUIRED_AUTH_ROUTES')
    ],
    'api' => [
        'auth_ttl' => 3600 * 24 * 7,// 默认token登录过期时间：7天
        'jwt_ttl' => '', // access token 过期时间，如果设置则会覆盖默认的jwt配置
        'jwt_refresh_ttl' => '',// refresh token 过期时间，如果设置则会覆盖默认的jwt配置
        'no_required_auth_routes' => getenv('API_NO_REQUIRED_AUTH_ROUTES'),
        'register_user_send_integral' => 80, // 注册用户赠送80积分
        'register_user_send_space_size' => 1024 * 1024 * 1024 * 1, // 注册用户赠送1G空间
    ],
    'design_site_url' => getenv('DESIGN_SITE_URL'),
    'upload_chunk_tmp_file' => dirname(__DIR__) . '/runtime/upload-chunks' // 上传大文件分片切片目录
];
