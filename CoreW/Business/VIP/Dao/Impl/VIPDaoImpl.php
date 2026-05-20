<?php

namespace CoreW\Business\VIP\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\VIP\Dao\VIPDao;

class VIPDaoImpl extends AdvancedDaoImpl implements VIPDao
{

    protected $table = 'gv_vip';

    public function getByNickname($nickname)
    {
        return $this->getByFields(['nickname' => $nickname]);
    }

    public function getByEmail($email)
    {
        return $this->getByFields(['email' => $email]);
    }

    public function getByPhone($phone)
    {
        return $this->getByFields(['phone' => $phone]);
    }

    public function getByInviteCode($inviteCode)
    {
        return $this->getByFields(['inviteCode' => $inviteCode]);
    }

    public function getByUUID($uuid)
    {
        return $this->getByFields(['uuid' => $uuid]);
    }

    public function declares() : array
    {
        return [
            'serializes' => [
            ],
            'orderbys'   => [
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
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}
