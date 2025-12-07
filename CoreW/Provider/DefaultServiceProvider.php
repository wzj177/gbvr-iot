<?php


namespace CoreW\Provider;


use CoreW\Context\BootableProviderInterface;
use CoreW\Bfw;
use CoreW\Dao\DaoProxy;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class DefaultServiceProvider implements ServiceProviderInterface, BootableProviderInterface
{
    private string $defaultRootNamespace = "CoreW\\Business\\";

    public function register(Container $app): void
    {
        $app['autoload.object_maker.service'] = function ($app) {
            return function ($namespace, $name) use ($app) {
                return $this->loadService($app, $namespace, $name);
            };
        };

        $app['autoload.object_maker.dao'] = function ($app) {
            return function ($namespace, $name) use ($app) {
                $class = "{$namespace}\\Dao\\Impl\\{$name}Impl";
                if (str_starts_with($namespace, $this->defaultRootNamespace)) {
                    $ctNamespace = "{$this->getNamespace()}\\{$namespace}";
                    $ctClass = "{$ctNamespace}\\Dao\\Impl\\{$name}Impl";
                    if (class_exists($ctClass)) {
                        $class = $ctClass;
                    }
                }

                return new DaoProxy($app, new $class($app), $app['dao.metadata_reader'], $app['dao.serializer'], $app['dao.cache.array_storage']);
//                return new $class($app);
            };
        };
    }

    public function boot(Bfw $BytFramework)
    {
    }

    private function loadService($bfw, $namespace, $name)
    {
        $class = "{$namespace}\\Service\\Impl\\{$name}Impl";
        // 比如目录是:Modules/Api/Bfw/User,这样的结构的命名空间就是 Modules/Api/Bfw/User/Service/UserServiceImpl
        if (str_starts_with($namespace, $this->defaultRootNamespace)) {
            $ctNamespace = "{$this->getNamespace()}\\{$namespace}";
            $ctClass = "{$ctNamespace}\\Service\\Impl\\{$name}Impl";
            if (class_exists($ctClass)) {
                $class = $ctClass;
            }
        }

        return new $class($bfw);
    }

    /**
     * Gets the Bundle namespace.
     *
     * @return string The Bundle namespace
     */
    private function getNamespace()
    {
        $class = get_class($this);

        return substr($class, 0, strrpos($class, '\\'));
    }
}