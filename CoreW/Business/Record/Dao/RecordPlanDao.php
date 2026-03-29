<?php

namespace CoreW\Business\Record\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface RecordPlanDao extends AdvancedDaoInterface
{
    public function getByName(string $name): ?array;
}
