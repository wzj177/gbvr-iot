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

use Workerman\Worker;

return [
    // File update detection and automatic reload
    'monitor' => [
        'handler' => process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            // Monitor these directories
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                core_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app'), glob(base_path() . '/plugin/*/config'), glob(base_path() . '/plugin/*/api')),
            // Files with these suffixes will be monitored
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env'
            ],
            'options' => [
                'enable_file_monitor' => !Worker::$daemonize && DIRECTORY_SEPARATOR === '/',
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ]
        ]
    ],
    'redis_consumer_fast' => [
        'handler' => Webman\RedisQueue\Process\Consumer::class,
        'count' => 8,
        'constructor' => [
            // 消费者类目录
            'consumer_dir' => app_path() . '/queue/redis/fast'
        ]
    ],
//    'http-chunk' => [
//        'listen' => 'http://0.0.0.0:8585',
//        'handler' => \process\HttpChunk::class,
//    ],
    'ScheduleTask' => [
        'handler' => app\process\ScheduleTaskProcess::class
    ],
//    'Gb28181DeviceCatalogQueryTask' => [
//        'handler' => app\process\Gb28181DeviceCatalogQueryTaskProcess::class
//    ],
    'Gb28181DeviceStatusCheckTask' => [
        'handler' => app\process\Gb28181DeviceStatusCheckTaskProcess::class
    ],
//    'RefreshMediaServerStatusTask' => [
//        'handler' => app\process\RefreshMediaServerStatusTaskProcess::class
//    ],
    'CleanupRtpPortAndClearStreamSessionTask' => [
        'handler' => app\process\CleanupRtpPortAndClearStreamSessionTaskProcess::class
    ],
    'SubscriptionRenewTask' => [
        'handler' => app\process\SubscriptionRenewTaskProcess::class
    ],
];
