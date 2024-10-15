<?php

namespace CoreW\Business\Lock;

interface LockInterface
{
    public function exec($key, $fn, ?int $ex = null);
}