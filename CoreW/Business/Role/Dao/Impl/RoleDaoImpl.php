<?php

namespace CoreW\Business\Role\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Role\Dao\RoleDao;

class RoleDaoImpl extends AdvancedDaoImpl implements RoleDao
{

    protected $table = 'gv_role';

    public function getByCode($code)
    {
        return $this->getByFields(['code' => $code]);
    }

    public function findByCodes($codes)
    {
        return $this->findInField('code', $codes);
    }

    public function findByIds(array $ids)
    {
        return $this->findInField('id', $ids);
    }

    public function getByName($name)
    {
        return $this->getByFields(['name' => $name]);
    }

    public function declares() : array
    {
        return [
            'serializes' => [
                'data' => 'json',
            ],
            'orderbys'   => [
                'id',
                'createdTime',
                'updatedTime',
            ],
            'conditions' => [
                'id = :id',
                'id IN (:ids)',
                'name = :name',
                'code = :code',
                'code NOT IN (:excludeCodes)',
                'code LIKE :codeLike',
                'name LIKE :nameLike',
                'createdUserId = :createdUserId',
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}
