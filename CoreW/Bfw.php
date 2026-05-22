<?php


namespace CoreW;

use CoreW\Context\BootableProviderInterface;
use CoreW\Context\ContainerAutoloader;
use CoreW\Context\EventListenerProviderInterface;
use CoreW\Dao\Annotation\MetadataReader;
use CoreW\Dao\ArrayStorage;
use CoreW\Dao\CacheStrategy\RowStrategy;
use CoreW\Dao\CacheStrategy\TableStrategy;
use CoreW\Dao\FieldSerializer;
use CoreW\Dao\RedisCache;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use support\Redis;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Bfw extends Container
{
    private $providers = [];

    public $registers = [];

    protected $booted = false;

    public function __construct(array $values = [])
    {
        $this['debug'] = false;
        $this['logger'] = null;
        $this['interceptors'] = new \ArrayObject();
        $this['migration.directories'] = new \ArrayObject();
        $this['autoload.aliases'] = new \ArrayObject(['' => "CoreW\\Business"]);
        $this['dispatcher'] = function () {
            return new EventDispatcher();
        };

        $this['autoloader'] = function ($bfw) {
            return new ContainerAutoloader(
                $bfw,
                $bfw['autoload.aliases'],
                [
                    'service' => $bfw['autoload.object_maker.service'],
                    'dao'     => $bfw['autoload.object_maker.dao'],
                ]
            );
        };


        $this['dao.metadata_reader'] = function ($biz) {
            if ($biz['debug']) {
                $cacheDirectory = null;
            } else {
                $cacheDirectory = $biz['cache_directory'] . DIRECTORY_SEPARATOR . 'dao_metadata';
            }

            return new MetadataReader($cacheDirectory);
        };

        $this['dao.cache.redis_wrapper'] = function ($biz) {
            return new RedisCache($biz['redis'], $biz['dispatcher']);
        };

        $this['dao.serializer'] = function () {
            return new FieldSerializer();
        };

        $this['dao.cache.array_storage'] = null;

        $this['dao.cache.enabled'] = config('redis.dao-cache', null) ? true : false;


        $this['dao.cache.strategy.default'] = function ($biz) {
            return $biz['dao.cache.strategy.table'];
        };

        $this['dao.cache.strategy.table'] = function ($biz) {

            return new TableStrategy($biz['dao.cache.redis_wrapper'], $biz['dao.cache.array_storage']);
        };

        $this['dao.cache.strategy.row'] = function ($biz) {
            return new RowStrategy($biz['dao.cache.redis_wrapper'], $biz['dao.metadata_reader']);
        };

        $this['array_storage'] = function () {
            return new ArrayStorage();
        };
        $this['dao.serializer'] = function () {
            return new FieldSerializer();
        };

        $this['redis'] = function () {
            return Redis::connection('dao-cache');
        };


        $this['lock.flock.directory'] = null;

        $this['lock.store'] = function ($biz) {
            return new \Symfony\Component\Lock\Store\FlockStore($biz['lock.flock.directory']);
        };

        $this['lock.factory'] = function ($biz) {
            return new \Symfony\Component\Lock\LockFactory($biz['lock.store']);
        };

        parent::__construct($values);
    }

    public function init()
    {
        foreach ($this->registers as $register) {
            if (is_string($register) && class_exists($register)) {
                $this->register(new $register);
            } else if ($register instanceof ServiceProviderInterface) {
                $this->register($register);
            } else if (is_array($register)) {
                [$pro, $values] = $register;
                if (is_string($pro) && class_exists($pro)) {
                    $this->register(new $pro, $values);
                } else if ($pro instanceof ServiceProviderInterface) {
                    $this->register($pro, $values);
                }
            }
        }
        $this->boot();
    }

    public function getIsInitialized()
    {
        return $this->booted;
    }

    /**
     * @param ServiceProviderInterface $provider
     * @param array $values
     * @return $this|Bfw
     */
    public function register(ServiceProviderInterface $provider, array $values = [])
    {
        $this->providers[] = $provider;
        parent::register($provider, $values);

        return $this;
    }

    private function boot()
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        foreach ($this->providers as $provider) {
            if ($provider instanceof EventListenerProviderInterface) {
                $provider->subscribe($this, $this['dispatcher']);
            }

            if ($provider instanceof BootableProviderInterface) {
                $provider->boot($this);
            }
        }
    }

    public function service($alias)
    {
        return $this['autoloader']->autoload('service', $alias);
    }

    public function dao($alias)
    {
        return $this['autoloader']->autoload('dao', $alias);
    }
}