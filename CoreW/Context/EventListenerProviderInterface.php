<?php


namespace CoreW\Context;


use Pimple\Container;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

interface EventListenerProviderInterface
{
    public function subscribe(Container $app, EventDispatcherInterface $dispatcher);
}