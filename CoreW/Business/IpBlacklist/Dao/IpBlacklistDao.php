<?php

namespace CoreW\Business\IpBlacklist\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface IpBlacklistDao extends AdvancedDaoInterface
{
    public function getByIpAndType($ip, $type);
}
