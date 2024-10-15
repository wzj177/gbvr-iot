<?php


namespace CoreW\Provider;


use Pimple\Container;
use Pimple\ServiceProviderInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use CoreW\Context\EventListenerProviderInterface;

class EventSubscriberProvider implements ServiceProviderInterface, EventListenerProviderInterface
{

    public function subscribe(Container $app, EventDispatcherInterface $dispatcher)
    {
        $file = config_path() . DIRECTORY_SEPARATOR . 'event_subscribers.php';
        if (!is_file($file)) {
            return;
        }

        $subscribers = require $file;
        if (empty($subscribers)) {
            return;
        }

        foreach ($subscribers as $key => $class) {
            if (!class_exists($class)) {
                continue;
            }
            $subscriber = new $class($app);
            $dispatcher->addSubscriber($subscriber);
        }
    }

    /**
     * Registers services on the given container.
     *
     * This method should only be used to configure services and parameters.
     * It should not get services.
     */
    public function register(Container $pimple)
    {
        // TODO: Implement register() method.
    }
}