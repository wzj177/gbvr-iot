<?php

namespace CoreW\Business\User\Service\Impl;


use CoreW\Business\BizEnum;
use CoreW\Business\IpBlacklist\Service\IpBlacklistService;
use CoreW\Business\BaseService;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\Role\Service\RoleService;
use CoreW\Business\Setting\Service\SettingService;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Business\User\Dao\TokenDao;
use CoreW\Business\User\Dao\UserBindDao;
use CoreW\Business\User\Dao\VIPProfileDao;
use CoreW\Business\User\Exception\UserException;
use CoreW\Business\User\Service\UserService;
use CoreW\Business\User\Dao\UserDao;
use support\utils\ArrayToolkit;
use support\utils\SimpleValidator;
use support\utils\StringToolkit;
use Symfony\Contracts\EventDispatcher\Event;

class UserServiceImpl extends BaseService implements UserService
{
    public function getUserById($id)
    {
        return $this->getUserDao()->get($id);
    }

    public function countUsers(array $conditions)
    {
        if (isset($conditions['nickname'])) {
            $conditions['nickname'] = strtoupper($conditions['nickname']);
        }

        return $this->getUserDao()->count($conditions);
    }

    public function searchUsers(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        if (isset($conditions['nickname'])) {
            $conditions['nickname'] = strtoupper($conditions['nickname']);
        }

        $users = $this->getUserDao()->search($conditions, $orderBys, $start, $limit, $columns);

        return UserSerialize::unserializes($users);
    }

    public function createUser(array $fields)
    {
        if (empty($fields['email']) || !SimpleValidator::email($fields['email'])) {
            throw UserException::EMAIL_INVALID();
        }

        if (!$this->isEmailAvaliable($fields['email'])) {
            throw UserException::EMAIL_EXISTED();
        }
        $user = array_merge($this->getCreatedUserFields(), $fields);
        //        $user['roles'] = empty($user['roles']) ? ['ROLE_SUBPER_ADMIN'] : $user['roles'];
        $user['type'] = $user['type'] ?? 'default';
        $user['createdTime'] = time();
        $user['salt'] = $this->makeSalt();
        $user['password'] = $this->getPasswordEncoder()->hash($fields['password'], $user['salt']);
        $user['setup'] = 1;
        $user['uuid'] = $this->generateUUID();
        $this->beginTransaction();
        try {
            $newUser = $this->getUserDao()->create($user);
            //            $this->createUserProfile($newUser);
            $this->commit();
            return $newUser;
        } catch (\Exception $exception) {
            $this->rollback();
            throw $exception;
        }
    }


    public function updateUser($id, array $fields)
    {
        $user = $this->getUser($id);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        // 不允许修改系统用户的某些字段
        if ($user['type'] === 'system') {
            $protectedFields = ['email', 'nickname', 'roles', 'type'];
            foreach ($protectedFields as $field) {
                if (isset($fields[$field])) {
                    $this->createNewException(UserException::SYSTEM_USER_NOT_ALLOWED_MODIFY());
                }
            }
        }

        // 不允许修改密码、salt等敏感字段
        unset($fields['password'], $fields['salt']);

        // 如果有 roles 字段，确保是数组格式
        if (isset($fields['roles'])) {
            if (!is_array($fields['roles'])) {
                $fields['roles'] = explode(',', $fields['roles']);
            }
        }

        $fields['updatedTime'] = time();

        return $this->getUserDao()->update($id, $fields);
    }

    public function deleteUserById($id)
    {
        $user = $this->getUser($id);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        if ($user['type'] === 'system') {
            $this->createNewException(UserException::SYSTEM_USER_NOT_ALLOWED_DELETE());
        }

        if ($user['nickname'] === 'admin') {
            $this->createNewException(UserException::SYSTEM_USER_NOT_ALLOWED_DELETE());
        }

        $this->getUserDao()->delete($id);

        $this->dispatchEvent('user.delete', new Event($user));

        return true;
    }


    public function getUser($id, $lock = false)
    {
        $user = $this->getUserDao()->get($id, ['lock' => $lock]);

        return !$user ? null : UserSerialize::unserialize($user);
    }

    public function getUserByUUID($uuid)
    {
        return $this->getUserDao()->getByUUID($uuid);
    }

    public function initSystemUsers()
    {
        $users = [
            [
                'type'  => 'system',
                'roles' => ['ROLE_SUPER_ADMIN'],
            ],
        ];
        foreach ($users as $user) {
            $existsUser = $this->getUserDao()->getUserByType($user['type']);

            if (!empty($existsUser)) {
                continue;
            }

            $user['nickname'] = $this->generateNickname($user) . '(系统用户)';
            $user['emailVerified'] = 1;
            $user['orgId'] = 1;
            $user['orgCode'] = '1.';
            $user['password'] = $this->getRandomChar();
            $user['email'] = $this->generateEmail($user);
            $user['salt'] = $this->makeSalt();
            $user['password'] = $this->getPasswordEncoder()->hash($user['password'], $user['salt']);
            $user = UserSerialize::unserialize(
                $this->getUserDao()->create(UserSerialize::serialize($user))
            );

            $profile = [];
            $profile['id'] = $user['id'];
        }
    }


    public function deleteUserBindByUserId($userId)
    {
        return $this->getUserBindDao()->deleteByToId($userId);
    }

    public function findUserLikeNickname($nickname)
    {
        return $this->getUserDao()->findUserLikeNickname($nickname);
    }


    public function getSimpleUser($id)
    {
        $user = $this->getUser($id);

        $simple = [];

        $simple['id'] = $user['id'];
        $simple['nickname'] = $user['nickname'];
        $simple['title'] = $user['title'];
        $simple['avatar'] = '';

        return $simple;
    }

    public function getUserByNickname($nickname)
    {
        $user = $this->getUserDao()->getByNickname($nickname);

        return !$user ? null : UserSerialize::unserialize($user);
    }

    /**
     * 根据用户名/邮箱/手机号精确查找用户
     * @param $keyword
     * @param false $isFilterDestroyed
     * @return mixed
     */
    public function getUserByLoginField($keyword, $isFilterDestroyed = false)
    {
        if (SimpleValidator::email($keyword)) {
            $user = $this->getUserDao()->getByEmail($keyword);
        } else if (SimpleValidator::mobile($keyword)) {
            $user = $this->getUserDao()->getByVerifiedMobile($keyword);
        } else {
            $user = $this->getUserDao()->getByNickname($keyword);
        }

        if (isset($user['type']) && 'system' == $user['type']) {
            return null;
        }

        if ($isFilterDestroyed && 1 == $user['destroyed']) {
            return null;
        }

        return !$user ? null : UserSerialize::unserialize($user);
    }

    public function getUserByEmail($email)
    {
        if (empty($email)) {
            return null;
        }

        $user = $this->getUserDao()->getByEmail($email);

        return !$user ? null : UserSerialize::unserialize($user);
    }

    public function findUsersByIds(array $ids)
    {
        $users = UserSerialize::unserializes(
            $this->getUserDao()->findByIds($ids)
        );

        return ArrayToolkit::index($users, 'id');
    }

    public function findUnDestroyedUsersByIds($ids)
    {
        $users = UserSerialize::unserializes(
            $this->getUserDao()->findUnDestroyedUsersByIds($ids)
        );

        return ArrayToolkit::index($users, 'id');
    }


    public function setEmailVerified($userId)
    {
        $this->getUserDao()->update($userId, ['emailVerified' => 1]);
        $user = $this->getUser($userId);
        $this->dispatchEvent('email.verify', new Event($user));
    }

    public function changeNickname($userId, $nickname)
    {
        $user = $this->getUser($userId);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        if (!SimpleValidator::nickname($nickname)) {
            $this->createNewException(UserException::NICKNAME_INVALID());
        }

        $existUser = $this->getUserDao()->getByNickname($nickname);

        if ($existUser && $existUser['id'] != $userId) {
            $this->createNewException(UserException::NICKNAME_EXISTED());
        }

        $updatedUser = $this->getUserDao()->update($userId, ['nickname' => $nickname]);
        $this->dispatchEvent('user.change_nickname', new Event($updatedUser));
    }

    public function changeEmail($userId, $email)
    {
        if (!SimpleValidator::email($email)) {
            $this->createNewException(UserException::EMAIL_INVALID());
        }

        $user = $this->getUserDao()->getByEmail($email);

        if ($user && $user['id'] != $userId) {
            $this->createNewException(UserException::EMAIL_EXISTED());
        }

        $updatedUser = $this->getUserDao()->update($userId, ['email' => $email]);
        $this->dispatchEvent('user.change_email', new Event($updatedUser));

        return $updatedUser;
    }

    public function isNicknameAvaliable($nickname)
    {
        if (empty($nickname)) {
            return false;
        }

        $user = $this->getUserDao()->getByNickname($nickname);

        return empty($user);
    }

    public function isEmailAvaliable($email)
    {
        if (empty($email)) {
            return false;
        }

        $user = $this->getUserDao()->getByEmail($email);

        return empty($user);
    }

    public function isMobileAvaliable($mobile)
    {
        if (empty($mobile)) {
            return false;
        }

        $user = $this->getUserDao()->getByVerifiedMobile($mobile);

        return empty($user);
    }

    public function changePassword($id, $password, $currentIp)
    {
        if (empty($password)) {
            $this->createNewException(CommonBizException::ERROR_PARAMETER());
        }

        if (!$this->validatePassword($password)) {
            $this->createNewException(UserException::PASSWORD_INVALID());
        }

        $user = $this->getUser($id);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        $salt = $this->makeSalt();

        $fields = [
            'salt'     => $salt,
            'password' => $this->getPasswordEncoder()->hash($password, $salt),
        ];

        $this->getUserDao()->update($id, $fields);

        $this->refreshLoginSecurityFields($user['id'], $currentIp);

        $this->dispatchEvent('user.change_password', $user);

        return true;
    }

    public function refreshLoginSecurityFields($userId, $ip)
    {
        $fields = [
            'lockDeadline'                  => 0,
            'consecutivePasswordErrorTimes' => 0,
            'lastPasswordFailTime'          => 0,
        ];

        $this->getUserDao()->update($userId, $fields);
        $this->getIpBlacklistService()->clearFailedIp($ip);
    }

    public function isMobileUnique($mobile)
    {
        $count = $this->countUsers(['wholeVerifiedMobile' => $mobile]);

        if ($count > 0) {
            return false;
        }

        return true;
    }

    public function verifyInSaltOut($in, $salt, $out)
    {
        return $out == $this->getPasswordEncoder()->hash($in, $salt);
    }

    public function verifyPasswordById($id, $password)
    {
        $user = $this->getUser($id);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        return $this->verifyInSaltOut($password, $user['salt'], $user['password']);
    }

    public function verifyPasswordByUser($user, $password)
    {
        return $this->verifyInSaltOut($password, $user['salt'], $user['password']);
    }

    public function getUserByType($type)
    {
        return $this->getUserDao()->getUserByType($type);
    }

    public function generateNickname($registration, $maxLoop = 100)
    {
        $rawNickname = $registration['nickname'] ?? '';
        if (!empty($rawNickname)) {
            $rawNickname = preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-z0-9_.]+/u', '', $rawNickname);
            $rawNickname = str_replace(['-'], ['_'], $rawNickname);

            if (!SimpleValidator::nickname($rawNickname)) {
                $rawNickname = '';
            }
            if ($this->isNicknameAvaliable($rawNickname)) {
                return $rawNickname;
            }
        }

        if (empty($rawNickname)) {
            $rawNickname = 'user';
        }
        $rawLen = (strlen($rawNickname) + mb_strlen($rawNickname, 'utf-8')) / 2;
        if ($rawLen > 12) {
            $rawNickname = substr($rawNickname, 0, -6);
        }
        for ($i = 0; $i < $maxLoop; ++$i) {
            $nickname = $rawNickname . substr($this->getRandomChar(), 0, 6);

            if ($this->isNicknameAvaliable($nickname)) {
                break;
            }
        }

        return $nickname;
    }

    public function generateEmail($registration, $maxLoop = 100)
    {
        for ($i = 0; $i < $maxLoop; ++$i) {
            $registration['email'] = 'user_' . substr($this->getRandomChar(), 0, 9) . '@vr.net';

            if ($this->isEmailAvaliable($registration['email'])) {
                break;
            }
        }

        return $registration['email'];
    }


    public function changeUserRoles($id, array $roles, $currentUser)
    {
        if (empty($roles)) {
            $this->createNewException(UserException::ROLES_INVALID());
        }

        $user = $this->getUser($id);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        $currentUserRoles = $currentUser['roles'];

        $hiddenRoles = [];
        if (!in_array('ROLE_SUPER_ADMIN', $currentUser['roles'])) {
            $userRoles = $user['roles'];
            $hiddenRoles = array_diff($userRoles, $currentUserRoles);
        }

        $roleItems = $this->getRoleService()->searchRoles(['createdUserId' => $currentUser['id']], 'created', 0, 9999);
        $allowedRoles = array_merge($currentUserRoles, ArrayToolkit::column($roleItems, 'code'));
        $notAllowedRoles = array_diff($roles, $allowedRoles);

        if (!empty($notAllowedRoles) && !in_array('ROLE_SUPER_ADMIN', $currentUser['roles'], true)) {
            $this->createNewException(UserException::ROLES_INVALID());
        }

        $roles = array_merge($roles, $hiddenRoles);

        $user = $this->getUserDao()->update($id, ['roles' => $roles]);

        return UserSerialize::unserialize($user);
    }

    public function makeToken($type, $userId = null, $expiredTime = null, $data = '', $args = [])
    {
        $token = [];
        $token['type'] = $type;
        $token['userId'] = $userId ? (int)$userId : 0;
        $token['token'] = base_convert(sha1(uniqid(mt_rand(), true)), 16, 36);
        $token['data'] = $data;
        $token['times'] = empty($args['times']) ? 0 : (int)($args['times']);
        $token['expiredTime'] = $expiredTime ? (int)$expiredTime : 0;
        $token['createdTime'] = time();
        $token = $this->getUserTokenDao()->create($token);

        return $token['token'];
    }

    public function getToken($type, $token)
    {
        $token = $this->getUserTokenDao()->getByToken($token);

        if (empty($token) || $token['type'] != $type) {
            return null;
        }

        if ($token['expiredTime'] > 0 && $token['expiredTime'] < time()) {
            return null;
        }

        return $token;
    }

    public function countTokens($conditions)
    {
        return $this->getUserTokenDao()->count($conditions);
    }

    public function deleteToken($type, $token)
    {
        $token = $this->getUserTokenDao()->getByToken($token);

        if (empty($token) || $token['type'] != $type) {
            return false;
        }

        $this->getUserTokenDao()->delete($token['id']);

        return true;
    }

    public function findBindsByUserId($userId)
    {
        $user = $this->getUserDao()->get($userId);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        return $this->getUserBindDao()->findByToId($userId);
    }

    public function unBindUserByTypeAndToId($type, $toId)
    {
        $user = $this->getUserDao()->get($toId);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        if (!$this->typeInOAuthClient($type)) {
            $this->createNewException(UserException::CLIENT_TYPE_INVALID());
        }

        $bind = $this->getUserBindByTypeAndUserId($type, $toId);
        if ($bind) {
            $convertedType = $this->convertOAuthType($type);
            $this->getUserBindDao()->deleteByTypeAndToId($convertedType, $toId);
            //            $currentUser = $this->getCurrentUser();
            $this->dispatchEvent('user.unbind', new Event($user, ['bind' => $bind, 'bindType' => $type, 'convertedType' => $convertedType]));
            //            $this->getLogService()->info('user', 'unbind', sprintf('用户名%s解绑成功，操作用户为%s', $user['nickname'], $currentUser['nickname']));
        }

        return $bind;
    }

    public function getUserBindByTypeAndFromId($type, $fromId)
    {
        $type = $this->convertOAuthType($type);

        return $this->getUserBindDao()->getByTypeAndFromId($type, $fromId);
    }

    public function findUserBindByTypeAndFromIds($type, $fromIds)
    {
        $type = $this->convertOAuthType($type);

        return $this->getUserBindDao()->findByTypeAndFromIds($type, $fromIds);
    }

    public function findUserBindByTypeAndToIds($type, $toIds)
    {
        $type = $this->convertOAuthType($type);

        return $this->getUserBindDao()->findByTypeAndToIds($type, $toIds);
    }

    public function getUserBindByToken($token)
    {
        return $this->getUserBindDao()->getByToken($token);
    }

    public function getUserBindByTypeAndUserId($type, $toId)
    {
        $user = $this->getUserDao()->get($toId);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        if (!$this->typeInOAuthClient($type)) {
            $this->createNewException(UserException::CLIENT_TYPE_INVALID());
        }

        $type = $this->convertOAuthType($type);

        return $this->getUserBindDao()->getByToIdAndType($type, $toId);
    }

    public function findUserBindByTypeAndUserId($type, $toId)
    {
        $user = $this->getUserDao()->get($toId);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        $type = $this->convertOAuthType($type);

        return $this->getUserBindDao()->findByToIdAndType($type, $toId);
    }

    public function bindUser($type, $fromId, $toId, $token)
    {
        $user = $this->getUserDao()->get($toId);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        if (!$this->typeInOAuthClient($type)) {
            $this->createNewException(UserException::CLIENT_TYPE_INVALID());
        }

        $convertedType = $this->convertOAuthType($type);

        $bind = $this->getUserBindDao()->create([
            'type'        => $convertedType,
            'fromId'      => $fromId,
            'toId'        => $toId,
            'token'       => empty($token['token']) ? '' : $token['token'],
            'createdTime' => time(),
            'expiredTime' => empty($token['expiredTime']) ? 0 : $token['expiredTime'],
        ]);

        $this->dispatchEvent('user.bind', new Event($user, ['bind' => $bind, 'bindType' => $type, 'convertedType' => $convertedType, 'token' => $token]));
    }

    public function markLoginInfo($user, $type = null)
    {
        $this->getUserDao()->update($user['id'], [
            'loginIp'   => $user['currentIp'],
            'loginTime' => time(),
        ]);
        //if user type is system,we do not record user login log
        if ('system' == $user['type']) {
            return false;
        }

        $this->refreshLoginSecurityFields($user['id'], $user['currentIp']);

        $this->getLogService()->info(LogEnum::MODULE_ADMIN, 'login_success', BizEnum::getLoginTypeItems($type) . '成功', [
            'currentIp' => $user['currentIp'],
        ]);
    }

    public function markLoginFailed($userId, $ip)
    {
        $user = $this->getUser($userId);
        if (empty($user)) {
            return;
        }

        $authConfig = $this->getSettingService()->get('auth', []);
        $temporaryLockEnabled = isset($authConfig['temporary_lock_enabled']) && $authConfig['temporary_lock_enabled'];

        if (!$temporaryLockEnabled) {
            return;
        }

        $maxErrorTimes = $authConfig['temporary_lock_max_error_times'] ?? 5;
        $lockDuration = $authConfig['temporary_lock_duration'] ?? 30; // 分钟

        $currentTime = time();
        $errorTimes = ($user['consecutivePasswordErrorTimes'] ?? 0) + 1;
        $lockDeadline = 0;
        $locked = 0;

        // 达到最大错误次数，锁定用户
        if ($errorTimes >= $maxErrorTimes) {
            $lockDeadline = $currentTime + ($lockDuration * 60);
            $locked = 1;

            $this->getLogService()->info(LogEnum::MODULE_USER, LogEnum::ACTION_LOCK, '密码错误次数过多，用户被临时锁定', [
                'userId'       => $userId,
                'errorTimes'   => $errorTimes,
                'lockDeadline' => $lockDeadline,
                'ip'           => $ip,
            ]);
        }

        $this->getUserDao()->update($userId, [
            'consecutivePasswordErrorTimes' => $errorTimes,
            'lastPasswordFailTime'          => $currentTime,
            'lockDeadline'                  => $lockDeadline,
            'locked'                        => $locked,
        ]);
    }

    public function checkLoginForbidden($userId, $ip)
    {
        $user = $this->getUser($userId);
        if (empty($user)) {
            return false;
        }

        $authConfig = $this->getSettingService()->get('auth', []);
        $temporaryLockEnabled = isset($authConfig['temporary_lock_enabled']) && $authConfig['temporary_lock_enabled'];

        if (!$temporaryLockEnabled) {
            return false;
        }

        $currentTime = time();

        // 检查是否在临时锁定期内
        if (!empty($user['lockDeadline']) && $user['lockDeadline'] > $currentTime) {
            $remainingMinutes = ceil(($user['lockDeadline'] - $currentTime) / 60);

            throw new UserException(UserException::TEMPORARY_LOCKED, "密码错误次数过多，账户已被临时锁定，请在 {$remainingMinutes} 分钟后重试");
        }

        // 检查是否已被手动锁定
        if (!empty($user['locked'])) {
            throw UserException::LOCKED_USER();
        }

        // 如果 lockDeadline 已过但 locked 还是 1，自动解锁
        if (!empty($user['locked']) && !empty($user['lockDeadline']) && $user['lockDeadline'] <= $currentTime) {
            $this->unlockUser($userId);
        }

        return false;
    }

    public function resetLoginFailed($userId)
    {
        $user = $this->getUser($userId);
        if (empty($user)) {
            return;
        }

        $authConfig = $this->getSettingService()->get('auth', []);
        $temporaryLockEnabled = isset($authConfig['temporary_lock_enabled']) && $authConfig['temporary_lock_enabled'];

        if (!$temporaryLockEnabled) {
            return;
        }

        // 重置错误次数
        $this->getUserDao()->update($userId, [
            'consecutivePasswordErrorTimes' => 0,
            'lastPasswordFailTime'          => 0,
        ]);
    }

    public function lockUser($id)
    {
        $user = $this->getUser($id);
        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        if (in_array('ROLE_SUPER_ADMIN', $user['roles'])) {
            $this->createNewException(UserException::LOCK_DENIED());
        }
        $this->getUserDao()->update($user['id'], ['locked' => 1]);
        $this->dispatchEvent('user.lock', new Event($user));

        return true;
    }

    public function unlockUser($id)
    {
        $user = $this->getUser($id);

        if (empty($user)) {
            $this->createNewException(UserException::NOTFOUND_USER());
        }

        $this->getUserDao()->update($user['id'], ['locked' => 0]);

        $this->dispatchEvent('user.unlock', new Event($user));

        return true;
    }


    public function hasAdminRoles($userId)
    {
        $user = $this->getUser($userId);

        $roles = $this->getRoleService()->findRolesByCodes($user['roles']);

        foreach ($roles as $role) {

            if (in_array('ROLE_SUPER_ADMIN', $role['code'])) {
                return true;
            }
        }

        return false;
    }

    public function makeUUID()
    {
        return sha1(uniqid(mt_rand(), true));
    }

    public function generateUUID()
    {
        $uuid = $this->makeUUID();
        $user = $this->getUserByUUID($uuid);

        if (empty($user)) {
            return $uuid;
        } else {
            return $this->generateUUID();
        }
    }

    public function initPassword($id, $newPassword, $currentIp = '127.0.0.1')
    {
        $this->beginTransaction();

        try {
            $fields = [
                'passwordInit' => 1,
            ];

            $this->changePassword($id, $newPassword, $currentIp);
            $this->getUserDao()->update($id, $fields);

            $this->commit();
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }

        return $this->getUserDao()->update($id, $fields);
    }

    public function validatePassword($password)
    {
        $auth = $this->getSettingService()->get('auth', []);
        $passwordLevel = empty($auth['password_level']) ? 'low' : $auth['password_level'];
        if ('low' == $passwordLevel && SimpleValidator::lowPassword($password)) {
            return true;
        }
        if ('middle' == $passwordLevel && SimpleValidator::middlePassword($password)) {
            return true;
        }
        if ('high' == $passwordLevel && SimpleValidator::highPassword($password)) {
            return true;
        }

        return false;
    }

    protected function typeInOAuthClient($type)
    {
        //        $types = array_keys(OAuthClientFactory::clients());
        $types = ['wechat_app', 'weixin', 'weixinweb', 'weixinmob'];

        return in_array($type, $types);
    }

    /**
     * @param $type
     *
     * @return string
     */
    private function convertOAuthType($type)
    {
        if ('weixinweb' == $type || 'weixinmob' == $type) {
            $type = 'weixin';
        }

        return $type;
    }

    protected function getRandomChar()
    {
        return StringToolkit::getRandomChar();
    }

    protected function getPasswordEncoder()
    {
        return StringToolkit::getPasswordEncoder();
    }

    protected function makeSalt()
    {
        return StringToolkit::makeSalt();
    }

    protected function getCreatedUserFields()
    {
        return [
            'verifiedMobile' => '',
            'email'          => '',
            'emailVerified'  => 0,
            'nickname'       => '',
            'createdIp'      => '',
            'registeredWay'  => '',
            'passwordInit'   => 1,
        ];
    }


    /**
     * @return UserDao
     */
    protected function getUserDao()
    {
        return $this->createDao('User:UserDao');

    }


    /**
     * @return UserBindDao
     */
    protected function getUserBindDao()
    {
        return $this->createDao('User:UserBindDao');
    }

    /**
     * @return TokenDao
     */
    protected function getUserTokenDao()
    {
        return $this->createDao('User:TokenDao');
    }


    /**
     * @return SettingService
     */
    protected function getSettingService()
    {
        return $this->createService('Setting:SettingService');
    }

    /**
     * @return IpBlacklistService
     */
    protected function getIpBlacklistService()
    {
        return $this->createService('IpBlacklist:IpBlacklistService');
    }

    /**
     * @return RoleService
     */
    protected function getRoleService()
    {
        return $this->createService('Role:RoleService');
    }
}
