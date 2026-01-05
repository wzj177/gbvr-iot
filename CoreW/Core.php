<?php

namespace CoreW;

use CoreW\Mail\MailServiceProvider;
use CoreW\Oauth2\OauthServiceProvider;
use CoreW\Provider\EventSubscriberProvider;
use CoreW\RateLimiter\RateLimiterServiceProvider;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use CoreW\Bfw as Biz;
use CoreW\Provider\DoctrineServiceProvider;
use CoreW\Provider\ExtensionsProvider;
use CoreW\Provider\MonologServiceProvider;
use CoreW\Provider\DefaultServiceProvider;
use Webman\Bootstrap;
use Workerman\Protocols\Http;
use Workerman\Timer;
use Workerman\Worker;

/**
 *
 *
 */
class Core implements Bootstrap
{
    /**
     * @var Bfw
     */
    protected static $_bizInstance = null;


    public static function start(?Worker $worker)
    {
        if ($worker && 'monitor' === $worker->name) {
            return;
        }
        $config = config('server');
        if (!empty($config['upload_tmp_file'])) {
            // 一句代码能够在多进程程序中防止并发错误 or use lock
            if (!is_dir($config['upload_tmp_file']) && mkdir($config['upload_tmp_file'], 0777)) {
                @chmod($config['upload_tmp_file'], 0777);
            }

            Http::uploadTmpDir($config['upload_tmp_file']);
        }
        // 或者 'webman' === $worker->name && 0 == $worker->id &&
//        if ('webman' === $worker->name && 0 == $worker->id && !empty($config['upload_tmp_file'])) {
//            if (!is_dir($config['upload_tmp_file'])) {
//                mkdir($config['upload_tmp_file'], 0777, true);
//                @chmod($config['upload_tmp_file'], 0777);
//            }
//
//            Http::uploadTmpDir($config['upload_tmp_file']);
//        }

        self::registerBiz($worker);
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     */
//    public static function __callStatic($name, $arguments)
//    {
//        return static::$_instance->{$name}(... $arguments);
//    }


    public static function initCiBiz()
    {
        return self::registerBiz(null);
    }

    /**
     *
     * 注册service 框架
     * @param Worker|null $worker
     */
    private static function registerBiz(?Worker $worker)
    {
        if (self::$_bizInstance instanceof Bfw) {
            return self::$_bizInstance;
        }
        $dbConn = config('database.default');
        $connections = config("database.connections");
        foreach ($connections as &$connection) {
            $connection['dbname'] = $connection['database'];
            $connection['user'] = $connection['username'];
            unset($connection['database'], $connection['username']);
        }
        $options = array_merge(config('app.biz_config'), [
            'dbs.default' => $dbConn,
            'dbs.options' => $connections
        ]);
        $biz = new Bfw($options);
        $biz->register(new DefaultServiceProvider());
        $biz->register(new DoctrineServiceProvider());
        $biz->register(new MonologServiceProvider(), [
            'monolog.logfile' => $biz['log_dir'] . '/' . date('Ym') . '/' . date('d') . '.log',
            'monolog.level' => $biz['debug'] ? Logger::DEBUG : Logger::INFO,
            'monolog.permission' => 0666
        ]);

        $biz->register(new EventSubscriberProvider());
        $biz->register(new ExtensionsProvider());
        $biz->register(new RateLimiterServiceProvider());
        $biz->register(new MailServiceProvider());
        $biz->register(new OauthServiceProvider());
        $biz->init();
        if ($worker) {
            self::dbKeepAlive($biz);
        }
        self::$_bizInstance = $biz;

        return $biz;
    }

    /**
     * db 存活
     * @link https://www.workerman.net/q/5923
     */
    private static function dbKeepAlive(Biz $biz)
    {
        /** @var $db Connection */
        $db = $biz['db'];
        //3600 * 7
        Timer::add(55, function () use ($db) {
//            echo date('Y-m-d H:i:s') . '：db heartbeat select 1', PHP_EOL, PHP_EOL;
            $db->executeQuery('select 1');
        });
    }

    /**
     * @return Bfw
     */
    public static function instance(): Bfw
    {
        return self::$_bizInstance ?? self::initCiBiz();
    }
}