<?php

namespace CoreW\Sdk\Iot;

use CoreW\Sdk\Iot\Driver\IotInterface;

class IotDriverFactory
{
    /**
     * @param string $driverName
     * @param array $config
     * @return IotInterface
     * @throws \Exception
     */
    public static function create(string $driverName, array $config): IotInterface
    {
        $driverList = config('iot');
        if (!isset($driverList[$driverName])) {
            throw new \Exception('Driver not found');
        }
        if (!class_exists($driverList[$driverName]['driver'])) {
            throw new \Exception('Driver class not found');
        }

        $config['debug'] = config('app.debug');

        return new $driverList[$driverName]['driver']($config);
    }
}