<?php

namespace CoreW\Business\Record\Dao\Impl;

use CoreW\Business\Record\Dao\RecordPlanDao;
use CoreW\Dao\AdvancedDaoImpl;

class RecordPlanDaoImpl extends AdvancedDaoImpl implements RecordPlanDao
{
    protected $table = 'gv_record_plan';

    public function declares(): array
    {
        return [
            'serializes' => [],
            'orderbys' => [
                'id',
                'created_at',
            ],
            'datetime' => [
                'created_at',
                'updated_at',
            ],
            'conditions' => [
                'id = :id',
                'id IN (:ids)',
                'name LIKE :nameLike',
                'status = :status',
                'partner_id = :partner_id',
            ],
        ];
    }

    public function getByName(string $name): ?array
    {
        $result = $this->getByFields(['name' => $name]);
        return $result ?: null;
    }
}
