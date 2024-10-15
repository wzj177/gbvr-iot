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

    'default' => getenv('DB_CONNECTION'),
    'connections' => [
        'mysql' => [
            'driver' => 'pdo_mysql',//mysql
            'host' => getenv('DB_HOST'),
            'port' => getenv('DB_PORT'),
            'database' => getenv('DB_DATABASE'),
            'username' => getenv('DB_USERNAME'),
            'password' => getenv('DB_PASSWORD'),
            'unix_socket' => getenv('DB_SOCKET'),
            'charset' => 'utf8',
            'collation' => 'utf8_general_ci',
            'prefix' => getenv('DB_PREFIX'),
            'strict' => true,
            'engine' => null,
        ],
    ],
];
