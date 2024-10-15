<?php

namespace CoreW\Business\VIP\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface VIPCompanyDao extends AdvancedDaoInterface
{
    public function getByUserId(int $userId);

    public function getByCode(string $code);

    public function getByName(string $name);
}
