<?php

/**
 * Object Storage Service (OSS) Configuration
 *
 * This file contains configuration settings for various cloud storage providers:
 * - Qiniu Cloud (Kodo)
 * - Alibaba Cloud (OSS)
 * - Tencent Cloud (COS)
 *
 * Each provider has its own configuration section with credentials and settings.
 * The active provider is determined by the 'attachment.type' setting in the database.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Qiniu Cloud Storage Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Qiniu Kodo (Object Storage)
    | Docs: https://developer.qiniu.com/kodo/sdk/php
    |
    */
    'qiniu' => [
        // Access Key - get from https://portal.qiniu.com/user/key
        'access_key' => env('QINIU_ACCESS_KEY', ''),

        // Secret Key - get from https://portal.qiniu.com/user/key
        'secret_key' => env('QINIU_SECRET_KEY', ''),

        // Bucket name - the storage container
        'bucket' => env('QINIU_BUCKET', ''),

        // Bucket domain - for generating file access URLs
        // Supports custom domain or default domain like https://xxx.bkt.clouddn.com
        'domain' => env('QINIU_DOMAIN', ''),

        // Storage zone -可选:华东, 华北, 华南, 北美, 东南亚
        'zone' => env('QINIU_ZONE', 'east_china'),

        // Upload protocol - http or https
        'protocol' => env('QINIU_PROTOCOL', 'https'),

        // Use CDN domain for file access
        'use_cdn' => env('QINIU_USE_CDN', false),

        // CDN domain (optional, overrides 'domain' when use_cdn is true)
        'cdn_domain' => env('QINIU_CDN_DOMAIN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alibaba Cloud OSS Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Alibaba Cloud Object Storage Service
    | Docs: https://help.aliyun.com/document_detail/32099.html
    |
    */
    'aliyun' => [
        // Access Key ID - get from https://ram.console.aliyun.com/manage/ak
        'access_key_id' => env('ALIYUN_OSS_ACCESS_KEY_ID', ''),

        // Access Key Secret - get from https://ram.console.aliyun.com/manage/ak
        'access_key_secret' => env('ALIYUN_OSS_ACCESS_KEY_SECRET', ''),

        // Bucket name - the storage container
        'bucket' => env('ALIYUN_OSS_BUCKET', ''),

        // Endpoint - OSS region endpoint
        // Example: oss-cn-hangzhou.aliyuncs.com
        // Full list: https://help.aliyun.com/document_detail/31837.html
        'endpoint' => env('ALIYUN_OSS_ENDPOINT', ''),

        // Region - e.g., oss-cn-hangzhou
        'region' => env('ALIYUN_OSS_REGION', 'oss-cn-hangzhou'),

        // Is CName - using custom domain
        'is_cname' => env('ALIYUN_OSS_IS_CNAME', false),

        // Custom domain (optional, when is_cname is true)
        'custom_domain' => env('ALIYUN_OSS_CUSTOM_DOMAIN', ''),

        // Use SSL/TLS
        'use_ssl' => env('ALIYUN_OSS_USE_SSL', true),

        // Storage type - Standard, IA, Archive, Cold Archive
        'storage_type' => env('ALIYUN_OSS_STORAGE_TYPE', 'Standard'),

        // Enable CDN acceleration
        'use_cdn' => env('ALIYUN_OSS_USE_CDN', false),

        // CDN domain (optional)
        'cdn_domain' => env('ALIYUN_OSS_CDN_DOMAIN', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tencent Cloud COS Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Tencent Cloud Object Storage Service
    | Docs: https://cloud.tencent.com/document/product/436
    |
    */
    'tencent' => [
        // Secret ID - get from https://console.cloud.tencent.com/cam/capi
        'secret_id' => env('TENCENT_COS_SECRET_ID', ''),

        // Secret Key - get from https://console.cloud.tencent.com/cam/capi
        'secret_key' => env('TENCENT_COS_SECRET_KEY', ''),

        // Bucket name - format: {bucket}-{appid}
        // Example: mybucket-1250000000
        'bucket' => env('TENCENT_COS_BUCKET', ''),

        // Region - e.g., ap-guangzhou, ap-shanghai, ap-beijing
        // Full list: https://cloud.tencent.com/document/product/436/6224
        'region' => env('TENCENT_COS_REGION', 'ap-guangzhou'),

        // CDN domain (optional, for CDN acceleration)
        'cdn_domain' => env('TENCENT_COS_CDN_DOMAIN', ''),

        // Use CDN domain for file access
        'use_cdn' => env('TENCENT_COS_USE_CDN', false),

        // App ID - part of the bucket name (number after the dash)
        'app_id' => env('TENCENT_COS_APP_ID', ''),

        // Use CDN HTTPS
        'cdn_secure' => env('TENCENT_COS_CDN_SECURE', true),

        // Use CDN for token
        'use_cdn_token' => env('TENCENT_COS_USE_CDN_TOKEN', false),
    ],
];
