<?php

namespace CoreW\Business\Role\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface RoleDao extends AdvancedDaoInterface
{
    public function getByCode($code);

    public function getByName($name);

    public function findByCodes($codes);
}
