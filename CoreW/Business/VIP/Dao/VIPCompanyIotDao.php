<?php

namespace CoreW\Business\VIP\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface VIPCompanyIotDao extends AdvancedDaoInterface
{
    public function getByCompanyId(int $companyId);
    public function getByUserId(int $userId);
}
