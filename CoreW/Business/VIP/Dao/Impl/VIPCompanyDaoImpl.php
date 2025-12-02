<?php

namespace CoreW\Business\VIP\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\VIP\Dao\VIPCompanyDao;

class VIPCompanyDaoImpl extends AdvancedDaoImpl implements VIPCompanyDao
{

    protected $table = 'gv_vip_company';

    public function getByUserId(int $userId)
    {
       return $this->getByFields(['userId' => $userId]);
    }

    public function getByCode(string $code)
    {
        return $this->getByFields(['code' => $code]);
    }

    public function getByName(string $name)
    {
        return $this->getByFields(['name' => $name]);
    }

    public function declares(): array
    {
        return [
            'serializes' => [ 
           ], 
            'orderbys' => [ 
                'id',
                'createdTime',
                'updatedTime',
           ], 
            'conditions' => [ 
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'createdTime >= :startTime',
                'createdTime <= :endTime',
                'userId = :userId',
                'code = :code',
                'name = :name',
                'status = :status',
                'status <> :noStatus',
           ],
            'timestamps' => [ 
                'createdTime',
                'updatedTime',
           ], 
        ];
    } 
}
