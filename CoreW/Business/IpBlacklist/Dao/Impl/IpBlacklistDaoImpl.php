<?php

namespace CoreW\Business\IpBlacklist\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\IpBlacklist\Dao\IpBlacklistDao;

class IpBlacklistDaoImpl extends AdvancedDaoImpl implements IpBlacklistDao
{

    protected $table = 'gv_ip_blacklist';

    public function getByIpAndType($ip, $type)
    {
        return $this->getByFields(['ip' => $ip, 'type' => $type]);
    }

    public function declares() : array
    {
        return [
            'orderbys'   => [
                'createdTime',
            ],
            'conditions' => [
                'ip = :ip',
                'type = :type',
            ],
            'timestamps' => [
                'createdTime',
            ],
        ];
    }
}
