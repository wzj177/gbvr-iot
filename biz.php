<?php

use CoreW\Bfw as Biz;
use CoreW\Provider\DoctrineServiceProvider;
use CoreW\Provider\MonologServiceProvider;
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$options = array(
    'db.options' => array(
        'dbname' => $_ENV['DB_DATABASE'] ?: 'vr2_diy',
        'user' => $_ENV['DB_USERNAME'] ?: 'root',
        'password' => $_ENV['DB_PASSWORD'] ?: 'root',
        'host' => $_ENV['DB_HOST'] ?: '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?: 3306,
        'driver' => 'pdo_mysql',
        'charset' => 'utf8',
    ),
    'redis.options' => array(
        'host' => getenv('REDIS_HOST'),
    ),
    'debug' => true,
    'log_dir' => __DIR__ . '/runtime/logs',
    'run_dir' => __DIR__ . '/runtime/run',
    'lock.flock.directory' => __DIR__ . '/runtime/run',
);

$biz = new Biz($options);
$biz->register(new DoctrineServiceProvider());
$biz->register(new MonologServiceProvider(), [
    'monolog.logfile' => $biz['log_dir'].'/phpmig-biz.log',
]);

$biz->init();

return $biz;