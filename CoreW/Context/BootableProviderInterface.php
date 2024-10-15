<?php


namespace CoreW\Context;


use CoreW\Bfw;

interface BootableProviderInterface
{
    public function boot(Bfw $BytFramework);
}