<?php


namespace CoreW\Business\VIP\Dao;


interface TokenDao
{
    public function get($id, array $options = array());

    public function getByToken($token);

    public function create($token);

    public function findByUserIdAndType($userId, $type);

    public function destroyTokensByUserId($userId);

    public function getByType($type);

    public function deleteTopsByExpiredTime($expiredTime, int $limit);

    public function deleteByTypeAndUserId($type, $userId);


    public function getLastedByUserIDAndType($userId, $type);
}