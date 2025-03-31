<?php

namespace CoreW\Business\VIP\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\VIP\Dao\VIPCompanyIotDao;

class VIPCompanyIotDaoImpl extends AdvancedDaoImpl implements VIPCompanyIotDao
{

    protected $table = 'vr_vip_company_iot';

    public function getByCompanyId(int $companyId)
    {
        return $this->getByFields(['companyId' => $companyId]);
    }

    public function getByUserId(int $userId)
    {
        return $this->getByFields(['userId' => $userId]);
    }

    public function getByAppId(string $appId)
    {
        return $this->getByFields(['appId' => $appId]);
    }

    public function declares(): array
    {
        return [
            'serializes' => [
                'api' => 'php'
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
                'companyId = :companyId',
                'userId = :userId',
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}
