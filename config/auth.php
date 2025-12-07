<?php


return [
    'token_storage' => env('TOKEN_STORAGE', 'db'),
    'admin' => [
        'auth_ttl' => 3600 * 24 * 7, // 默认token登录过期时间：7天
        'jwt_ttl' => '', // access token 过期时间，如果设置则会覆盖默认的jwt配置
        'jwt_refresh_ttl' => '',// refresh token 过期时间，如果设置则会覆盖默认的jwt配置
        'no_required_auth_routes' => env('ADMIN_NO_REQUIRED_AUTH_ROUTES')
    ],
    'api' => [
        'auth_ttl' => 3600 * 24 * 7,// 默认token登录过期时间：7天
        'jwt_ttl' => '', // access token 过期时间，如果设置则会覆盖默认的jwt配置
        'jwt_refresh_ttl' => '',// refresh token 过期时间，如果设置则会覆盖默认的jwt配置
        'no_required_auth_routes' => env('API_NO_REQUIRED_AUTH_ROUTES'),
        'register_user_send_integral' => 80, // 注册用户赠送80积分
        'register_user_send_space_size' => 1024 * 1024 * 1024 * 1, // 注册用户赠送1G空间
    ],
    'admin_token_handler' => env('ADMIN_AUTH_TOKEN_HANDLER', 'default'),
    'api_token_handler' => env('API_AUTH_TOKEN_HANDLER', 'default'),
    'oauth2' => [ // TODO: 后期放入配置表
        'qq' => [
            'open' => env('OAUTH2_QQ_OPEN', false),
            'key' => env('OAUTH2_QQ_KEY', null),
            'secret' => env('OAUTH2_QQ_SECRET', null)
        ]
    ]
];