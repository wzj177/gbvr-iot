<?php

namespace CoreW\Business\Common;

use CoreW\Core;
use support\Log;

abstract class BaseCrontabTask implements CrontabTaskInterface
{
    protected static ?CrontabTaskInterface $_instance = null;

    abstract public function execute() : void;

    public static function run() : void
    {
        if (static::$_instance === null) {
            static::$_instance = new static();
        }
        try {
            static::$_instance->execute();
        } catch (\Throwable $e) {
            Log::channel('crontab')->error("Execute Crontab Task Job Error:{$e->getMessage()}");
        }
    }

    /**
     * 获取日志实例
     * @return \Monolog\Logger
     */
    protected function log()
    {
        return Log::channel('crontab');
    }

    /**
     * 获取bfw实例
     * @return \CoreW\Bfw
     */
    protected function getBfw() : \CoreW\Bfw
    {
        return Core::instance();
    }
}