<?php


namespace CoreW\NotificationCenter;



use CoreW\Traits\Singleton;

abstract class BaseSender
{
    use Singleton;

    protected $afterSendInfo = [];
}