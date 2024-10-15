<?php


namespace Core\Util;


class Lock
{
    private $bfw;

    public function __construct($bfw)
    {
        $this->bfw = $bfw;
    }

    public function get($lockName, $lockTime = 30)
    {
        $this->getConnection()->connect('master');
        $result = $this->getConnection()->fetchAssoc("SELECT GET_LOCK(?,?) AS getLock", array('locker_' . $lockName, $lockTime));

        return $result['getLock'];
    }

    public function release($lockName)
    {
        $this->getConnection()->connect('master');
        $result = $this->getConnection()->fetchAssoc("SELECT RELEASE_LOCK(?) AS releaseLock", array('locker_' . $lockName));

        return $result['releaseLock'];
    }

    protected function getConnection()
    {
        return $this->bfw['db'];
    }
}