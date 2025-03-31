<?php

namespace CoreW\Business\Setting\Service\Impl;

use CoreW\Business\BaseService;

use CoreW\Business\Setting\Service\SettingService;
use CoreW\Business\Setting\Dao\SettingDao;
use CoreW\Dao\RedisCache;
use support\Redis;
use support\utils\AssetHelper;
use Webman\Config;

class SettingServiceImpl extends BaseService implements SettingService
{
    public function getAkServerConfig()
    {
        $config = [
            'api_url' => Config::get('app.ak_config.api_url'),
            'access_key' => Config::get('app.ak_config.access_key'),
            'zlmediakit_api' => Config::get('app.ak_config.zlmediakit_api'),
            'zlmediakit_secret' => Config::get('app.ak_config.zlmediakit_secret'),
            'debug' => Config::get('app.ak_config.debug'),
        ];

        $dbConfig = $this->get('ak_config', []);
        $dbConfig === null && $dbConfig = [];
        $currentConfig = array_merge($config, $dbConfig);
        $currentConfig['debug'] = intval($currentConfig['debug']) . '';

        return $currentConfig;
    }

    public function set($name, $value)
    {
        $this->getSettingDao()->deleteByName($name);
        $setting = [
            'name' => $name,
            'value' => json_encode($value),
        ];
        $res = $this->getSettingDao()->create($setting);
        if ($res) {
            $this->setCache($name, 'default', $res);
        }

        return $res;
    }


    public function get($name, $default = null, $namespace = 'default')
    {
        $cache = $this->getCache($name, $namespace);
        if (!empty($cache)) {
            return $this->getSettingValue($cache);
        }

        $setting = $this->getSettingDao()->findByNameAndNamespace($name, $namespace);

        if (empty($setting)) {
            return null;
        }

        $this->setCache($name, $namespace, $setting);



        return $this->getSettingValue($setting);
    }

    public function delete($name)
    {
        $res = $this->getSettingDao()->deleteByName($name);
        if ($res) {
            $this->getRedisCache()->del($this->getCacheKey($name));
        }
        return $res;
    }

    public function setByNamespace($namespace, $name, $value)
    {
        $this->getSettingDao()->deleteByNamespaceAndName($namespace, $name);
        $setting = [
            'namespace' => $namespace,
            'name' => $name,
            'value' => json_encode($value),
        ];
        $result = $this->getSettingDao()->create($setting);
        if ($result) {
            $this->setCache($name, $namespace, $result);
        }

        return $result;
    }


    public function deleteByNamespaceAndName($namespace, $name)
    {
        $res = $this->getSettingDao()->deleteByNamespaceAndName($namespace, $name);
        if ($res) {
            $this->getRedisCache()->del($this->getCacheKey($name, $namespace));
        }

        return $res;
    }

    protected function getSettingValue($setting)
    {
        if (empty($setting['value'])) {
            return null;
        }
        $value = is_string($setting['value']) ? json_decode($setting['value'], true) : $setting['value'];

        return $value;
    }

    /**
     * @return SettingDao
     */
    protected function getSettingDao()
    {
        return $this->createDao('Setting:SettingDao');

    }


    /**
     * 缓存设置
     *
     * @param $name
     * @param string $namespace
     * @param $setting
     */
    protected function setCache($name, $namespace, $setting)
    {
        $key = $this->getCacheKey($name, $namespace);
        $this->getRedisCache()->del($key);
        $this->getRedisCache()->set($key, serialize($setting));
    }

    /**
     * 获取缓存
     *
     * @param $name
     * @param string $namespace
     * @return mixed
     */
    protected function getCache($name, $namespace = 'default')
    {
        $value = $this->getRedisCache()->get($this->getCacheKey($name, $namespace));
        if (!empty($value)) {
            return unserialize($value);
        }

        return null;
    }

    /**
     * 获取缓存key
     *
     * @param $name
     * @param string $namespace
     * @return string
     */
    protected function getCacheKey($name, $namespace = 'default')
    {
        return $this->getSettingDao()->table() . ':' . $namespace . ':' . $name;
    }

    /**
     * @return \Illuminate\Redis\Connections\Connection
     */
    protected function getRedisCache()
    {
        return Redis::connection('default');
    }
}
