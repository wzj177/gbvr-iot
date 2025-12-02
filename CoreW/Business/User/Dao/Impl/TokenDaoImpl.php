<?php


namespace CoreW\Business\User\Dao\Impl;


use CoreW\Business\User\Dao\TokenDao;
use CoreW\Dao\GeneralDaoImpl;

class TokenDaoImpl extends GeneralDaoImpl implements TokenDao
{
    protected $table = 'gv_user_token';

    public function getByToken($token)
    {
        $sql = "SELECT * FROM {$this->table} WHERE token = ? LIMIT 1";

        return $this->db()->fetchAssoc($sql, [$token]) ?: null;
    }

    public function findByVIPIdAndType($userId, $type)
    {
        return $this->findByFields(['userId' => $userId, 'type' => $type]);
    }

    public function destroyTokensByVIPId($userId)
    {
        return $this->db()->delete($this->table, ['userId' => $userId]);
    }

    public function getByType($type)
    {
        $sql = "SELECT * FROM {$this->table} WHERE type = ?  and expiredTime > ? order  by createdTime DESC  LIMIT 1";

        return $this->db()->fetchAssoc($sql, [$type, time()]) ?: null;
    }

    public function deleteTopsByExpiredTime($expiredTime, int $limit)
    {
        $sql = "DELETE FROM {$this->table} WHERE expiredTime < ? LIMIT {$limit} ";

        return $this->db()->executeQuery($sql, [$expiredTime]);
    }

    public function deleteByTypeAndVIPId($type, $userId)
    {
        return $this->db()->delete($this->table, ['type' => $type, 'userId' => $userId]);
    }

    public function getLastedByVIPIdAndType($userId, $type)
    {
        $sql = "SELECT * FROM {$this->table} WHERE type = ?  and userId = ? and expiredTime > ? order  by id DESC  LIMIT 1";

        return $this->db()->fetchAssoc($sql, [$type, $userId, time()]) ?: null;
    }

    public function declares():array
    {
        return [
            'cache' => false,
            'serializes' => [
                'data' => 'php'
            ],
            'conditions' => [
                'type = :type'
            ],
        ];
    }
}