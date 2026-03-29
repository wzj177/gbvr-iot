<?php

namespace CoreW\Business\Record\Dao\Impl;

use CoreW\Business\Record\Dao\RecordPlanRangeDao;
use CoreW\Dao\AdvancedDaoImpl;

class RecordPlanRangeDaoImpl extends AdvancedDaoImpl implements RecordPlanRangeDao
{
    protected $table = 'gv_record_plan_range';

    public function declares(): array
    {
        return [
            'serializes' => [],
            'orderbys' => [
                'id',
                'week_day',
                'start_time',
            ],
            'timestamps' => [
                'created_at',
                'updated_at',
            ],
            'conditions' => [
                'id = :id',
                'id IN (:ids)',
                'record_plan_id = :record_plan_id',
                'week_day = :week_day',
            ],
        ];
    }

    public function findByPlanId(int $planId): array
    {
        return $this->findInField('record_plan_id', [$planId]);
    }

    public function deleteByPlanId(int $planId): int
    {
        return $this->db()->delete($this->table(), ['record_plan_id' => $planId]);
    }
}
