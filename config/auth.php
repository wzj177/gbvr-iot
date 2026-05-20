<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 认证配置
    |--------------------------------------------------------------------------
    |
    | 统一认证配置，包括 token 存储方式、JWT 配置等
    |
    */

    'token_storage'       => env('TOKEN_STORAGE', 'db'),

    /*
    |--------------------------------------------------------------------------
    | 管理端认证配置
    |--------------------------------------------------------------------------
    */
    'admin'               => [
        'auth_ttl'                => 3600 * 24 * 7, // 默认token登录过期时间：7天
        'jwt_ttl'                 => '', // access token 过期时间，如果设置则会覆盖默认的jwt配置
        'jwt_refresh_ttl'         => '',// refresh token 过期时间，如果设置则会覆盖默认的jwt配置
        'no_required_auth_routes' => env('ADMIN_NO_REQUIRED_AUTH_ROUTES'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API端认证配置
    |--------------------------------------------------------------------------
    */
    'api'                 => [
        'auth_ttl'                      => 3600 * 24 * 7,// 默认token登录过期时间：7天
        'jwt_ttl'                       => '', // access token 过期时间，如果设置则会覆盖默认的jwt配置
        'jwt_refresh_ttl'               => '',// refresh token 过期时间，如果设置则会覆盖默认的jwt配置
        'no_required_auth_routes'       => env('API_NO_REQUIRED_AUTH_ROUTES'),
        'register_user_send_integral'   => 80, // 注册用户赠送80积分
        'register_user_send_space_size' => 1024 * 1024 * 1024 * 1, // 注册用户赠送1G空间
    ],

    /*
    |--------------------------------------------------------------------------
    | 认证处理器配置
    |--------------------------------------------------------------------------
    */
    'admin_token_handler' => env('ADMIN_AUTH_TOKEN_HANDLER', 'default'),
    'api_token_handler'   => env('API_AUTH_TOKEN_HANDLER', 'default'),

    /*
    |--------------------------------------------------------------------------
    | 第三方登录配置
    |--------------------------------------------------------------------------
    */
    'oauth2'              => [ // TODO: 后期放入配置表
        'qq' => [
            'open'   => env('OAUTH2_QQ_OPEN', false),
            'key'    => env('OAUTH2_QQ_KEY', null),
            'secret' => env('OAUTH2_QQ_SECRET', null),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | JWT 配置
    |--------------------------------------------------------------------------
    */
    'jwt'                 => [
        'secret'            => env('JWT_SECRET', ''),
        'public_key'        => env('JWT_PUBLIC_KEY', ''),
        'private_key'       => env('JWT_PRIVATE_KEY', ''),
        'ttl'               => env('JWT_TTL', 60), // 单位：分钟
        'refresh_ttl'       => env('JWT_REFRESH_TTL', null), // 单位：分钟
        'algo'              => env('JWT_ALGO', 'RS256'), // 支持：HS256 RS256
        'leeway'            => env('JWT_LEEWAY', 0),
        'blacklist_enabled' => env('JWT_BLACKLIST_ENABLED', true),
    ],
];