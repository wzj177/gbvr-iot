<?php

namespace CoreW\Business\User\Service;

interface UserService
{
    public function getUser($id, $lock = false);

    public function getUserByUUID($uuid);

    public function initSystemUsers();


    public function countUsers(array $conditions);

    public function searchUsers(array $conditions, array $orderBy, $start, $limit, $columns = []);

    public function deleteUserBindByUserId($userId);

    public function findUserLikeNickname($nickname);


    public function getSimpleUser($id);


    public function getUserByNickname($nickname);

    /**
     * 根据用户名/邮箱/手机号精确查找用户
     * @param $keyword
     * @param false $isFilterDestroyed
     * @return mixed
     */
    public function getUserByLoginField($keyword, $isFilterDestroyed = false);


    public function getUserByEmail($email);

    public function findUsersByIds(array $ids);

    public function findUnDestroyedUsersByIds($ids);


    public function setEmailVerified($userId);

    public function changeEmail($userId, $email);

    public function isNicknameAvaliable($nickname);

    public function isEmailAvaliable($email);

    public function isMobileAvaliable($mobile);

    public function changePassword($id, $password, $currentIp);

    public function isMobileUnique($mobile);


    public function verifyInSaltOut($in, $salt, $out);

    public function verifyPasswordById($id, $password);

    public function verifyPasswordByUser($user, $password);


    public function getUserByType($type);

    public function generateNickname($registration, $maxLoop = 100);

    public function generateEmail($registration, $maxLoop = 100);


    public function changeUserRoles($id, array $roles, $currentUser);

    public function makeToken($type, $userId = null, $expiredTime = null, $data = '', $args = []);

    public function getToken($type, $token);

    public function countTokens($conditions);

    public function deleteToken($type, $token);

    public function findBindsByUserId($userId);

    public function unBindUserByTypeAndToId($type, $toId);

    public function getUserBindByTypeAndFromId($type, $fromId);

    public function findUserBindByTypeAndFromIds($type, $fromIds);

    public function findUserBindByTypeAndToIds($type, $toIds);

    public function getUserBindByToken($token);

    public function getUserBindByTypeAndUserId($type, $toId);

    public function findUserBindByTypeAndUserId($type, $toId);

    public function bindUser($type, $fromId, $toId, $token);

    public function markLoginInfo($user, $type = null);

    public function markLoginFailed($userId, $ip);

    public function checkLoginForbidden($userId, $ip);

    public function resetLoginFailed($userId);

    public function lockUser($id);

    public function unlockUser($id);

    public function hasAdminRoles($userId);

    public function makeUUID();

    public function generateUUID();

    public function initPassword($id, $newPassword, $currentIp = '127.0.0.1');

    public function validatePassword($password);

    public function createUser(array $fields);

    public function updateUser($id, array $fields);

    public function deleteUserById($id);
}
