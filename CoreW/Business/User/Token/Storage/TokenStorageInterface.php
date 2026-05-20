<?php


namespace CoreW\Business\User\Token\Storage;


interface TokenStorageInterface
{
    public function get($id, array $options = []);

    public function getByToken(string $token);

    public function create(array $token);

    public function wave(array $ids, array $diffs);

    public function delete($id);

    public function findByUserIdAndType($userId, $type);

    public function destroyTokensByUserId($userId);

    public function getByType($type);

    public function deleteTopsByExpiredTime($expiredTime, int $limit);

    public function deleteByTypeAndUserId($type, $userId);

    public function getLastedByUserIDAndType($userId, $type);
}