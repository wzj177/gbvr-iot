<?php

namespace CoreW\Business\Record\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface RecordPlanRangeDao extends AdvancedDaoInterface
{
    public function findByPlanId(int $planId): array;

    public function deleteByPlanId(int $planId): int;
}
