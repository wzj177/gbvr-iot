<?php


namespace CoreW\Business\User\Dao;


use CoreW\Dao\GeneralDaoInterface;

interface TokenDao extends GeneralDaoInterface
{
    public function get($id, array $options = []);

    public function getByToken($token);

    public function create($token);

    public function findByVIPIdAndType($userId, $type);

    public function destroyTokensByVIPId($userId);

    public function getByType($type);

    public function deleteTopsByExpiredTime($expiredTime, int $limit);

    public function deleteByTypeAndVIPId($type, $userId);


    public function getLastedByVIPIdAndType($userId, $type);
}