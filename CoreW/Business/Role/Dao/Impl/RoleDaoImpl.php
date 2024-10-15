<?php

namespace CoreW\Business\Role\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Role\Dao\RoleDao;

class RoleDaoImpl extends AdvancedDaoImpl implements RoleDao 
{

    protected $table = 'vr_role';

    public function getByCode($code)
    {
        return $this->getByFields(array('code' => $code));
    }

    public function findByCodes($codes)
    {
        return $this->findInField('code', $codes);
    }

    public function getByName($name)
    {
        return $this->getByFields(array('name' => $name));
    }

    public function declares():array
    {
        return [
            'serializes' => [
                'data' => 'json',
                'data_v2' => 'json',
           ], 
            'orderbys' => [ 
                'id',
                'createdTime',
                'updatedTime',
           ], 
            'conditions' => [
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
