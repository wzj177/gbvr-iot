<?php


return [
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