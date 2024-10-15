<?php


namespace CoreW\Traits\Token;


use CoreW\Business\VIP\Dao\TokenDao;

trait DbTokenStorage
{
    public function get($id, array $options = array())
    {
        return $this->getTokenDao()->get($id, $options);
    }

    public function getByToken(string $token)
    {
        return $this->getTokenDao()->getByToken($token);
    }

    public function create(array $token)
    {
        return $this->getTokenDao()->create($token);
    }

    public function delete($id)
    {
        return $this->getTokenDao()->delete($id);
    }

    public function wave(array $ids, array $diffs)
    {
        return $this->getTokenDao()->wave($ids, $diffs);
    }

    public function findByUserIdAndType($userId, $type)
    {
        return $this->getTokenDao()->findByVIPIdAndType($userId, $type);
    }

    public function destroyTokensByUserId($userId)
    {
        return $this->getTokenDao()->destroyTokensByVIPId($userId);
    }

    public function getByType($type)
    {
        return $this->getTokenDao()->getByType($type);
    }

    public function deleteTopsByExpiredTime($expiredTime, int $limit)
    {
        return $this->getTokenDao()->deleteTopsByExpiredTime($expiredTime, $limit);
    }

    public function deleteByTypeAndUserId($type, $userId)
    {
        return $this->getTokenDao()->deleteByTypeAndVIPId($type, $userId);
    }

    public function getLastedByUserIDAndType($userId, $type)
    {
        return $this->getTokenDao()->getLastedByVIPIdAndType($userId, $type);
    }
}