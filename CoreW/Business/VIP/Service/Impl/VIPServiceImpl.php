<?php

namespace CoreW\Business\VIP\Service\Impl;

use CoreW\Business\BaseService;

use CoreW\Business\BizEnum;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Business\Auth\Handler\TokenHandlerInterface;
use CoreW\Business\VIP\CurrentUser;
use CoreW\Business\VIP\Dao\LoginFormDto;
use CoreW\Business\VIP\Dao\VIPBindDao;
use CoreW\Business\VIP\Dao\VIPCompanyDao;
use CoreW\Business\VIP\Dao\VIPCompanyIotDao;
use CoreW\Business\VIP\Dao\VIPProfileDao;
use CoreW\Business\VIP\Exception\VIPException;
use CoreW\Business\VIP\Service\TokenService;
use CoreW\Business\VIP\Service\VIPService;
use CoreW\Business\VIP\Dao\VIPDao;
use CoreW\Mail\AbstractMail;
use CoreW\Oauth2\Client\QQOauthClient;
use CoreW\Oauth2\OauthFactory;
use CoreW\RateLimiter\Limiters\LoginSendEmailCodeRateLimiter;
use Ramsey\Uuid\Uuid;
use Respect\Validation\Validator as v;
use support\Redis;
use support\utils\ArrayToolkit;
use support\utils\AssetHelper;
use support\utils\SimpleValidator;
use support\utils\StringToolkit;
use Symfony\Contracts\EventDispatcher\Event;
use Webman\RedisQueue\Client;

class VIPServiceImpl extends BaseService implements VIPService
{
    /**
     * 新增会员使用空间
     *
     * @param int $userId
     * @param int $fileSize
     * @return false|int|null
     * @throws \CoreW\Dao\DaoException
     */
    public function addUsedSpaceSize(int $userId, int $fileSize)
    {
        $vip = $this->getVIPById($userId);
        if (empty($vip)) {
            return false;
        }

        return $this->getVIPDao()->update($userId, [
            'usedSpaceSize' => (int)$vip['usedSpaceSize'] + $fileSize
        ]);
    }

    /**
     * 扣减会员使用空间
     *
     * @param int $userId
     * @param int $fileSize
     * @return false|int|null
     * @throws \CoreW\Dao\DaoException
     */
    public function subUsedSpaceSize(int $userId, int $fileSize)
    {
        $vip = $this->getVIPById($userId);
        if (empty($vip) || 0 === $vip['usedSpaceSize']) {
            return false;
        }

        return $this->getVIPDao()->update($userId, [
            'usedSpaceSize' => (int)$vip['usedSpaceSize'] - $fileSize
        ]);
    }

    /**
     * 给账号发送邮箱
     * @param $email
     * @param $expireTime int 过期时间（单位：s)
     * @return mixed|string
     */
    public function sendAccountEmailLoginCode($email, $expireTime = 600)
    {
        $vip = $this->getVIPDao()->getByEmail($email);
        if (empty($vip)) {
            throw VIPException::LOGIN_SEND_EMAIL_CODE_NOTFOUND_USER();
        }
        // limit 限制
        /** @var LoginSendEmailCodeRateLimiter */
        $rateLimier = $this->bfw->offsetGet('login_send_email_code_rate_limiter');
        $rateLimier->handle($email);
        $code = mt_rand(100000, 999999);
        $value = $email . '_' . $code;
        $key = md5($value . '_' . time());
        Redis::set($key, $value, $expireTime);
        $mailOptions = [
            'to' => $email,
            'toName' => '用户你好',
            'template' => 'email_login_send_email_code',
            'params' => [
                'code' => $code,
                'duration' => 10
            ]
        ];
        $mailFactory = $this->bfw->offsetGet('mail_factory');
        /** @var $mail AbstractMail */
        $mail = $mailFactory($mailOptions);
        try {
            $mail->send();
            return $key;

        } catch (\Throwable $e) {
            $this->getSystemLogService()->warning('vip', 'send_email_login_code', "邮箱登录验证码发送失败：{$e->getMessage()}");
            return null;
        }

    }

    public function getVIPCompany(int $companyId)
    {
        return $this->getVIPCompanyDao()->get($companyId);
    }

    public function getVIPCompanyByUserId(int $userId)
    {
       return $this->getVIPCompanyDao()->getByUserId($userId);
    }

    /**
     * 提交企业认证
     * @param int $userId
     * @param array $fields
     * @return int|mixed|null
     * @throws \CoreW\Dao\DaoException
     */
    public function applyCompany(int $userId, array $fields)
    {
        $vip = $this->getVIPById($userId);
        if (empty($vip)) {
            throw VIPException::NOTFOUND_USER();
        }

        $vipCompany = $this->getVIPCompanyDao()->getByUserId($userId);
        $update = false;
        if (!empty($vipCompany)) {
            if (BizEnum::VIP_COMPANY_STATUS_OK === intval($vipCompany['status'])) {
                throw VIPException::OK_APPLY_COMPANY();
            }
            if (BizEnum::VIP_COMPANY_STATUS_WAIT === intval($vipCompany['status'])) {
                throw VIPException::ALREADY_APPLY_COMPANY();
            }
            $update = true;
        }

        $fields = ArrayToolkit::parts($fields, [
            'name',
            'code',
            'logo',
            'contactName',
            'contactMobile',
            'contactEmail',
            'license'
        ]);
        if (!ArrayToolkit::requireds($fields, ['name', 'code', 'contactName', 'contactMobile'])) {
            throw CommonBizException::ERROR_PARAMETER();
        }

        if (!SimpleValidator::mobile($fields['contactMobile'])) {
            throw VIPException::MOBILE_INVALID();
        }

        if (!empty($fields['contactEmail']) && !v::email()->validate($fields['contactEmail'])) {
            throw VIPException::EMAIL_INVALID();
        }

        $fields['status'] = BizEnum::VIP_COMPANY_STATUS_WAIT;
        if (!$update) {
            $fields['userId'] = $userId;
            $isUseName = $this->getVIPCompanyDao()->getByName($fields['code']);
            if (!empty($isUseName)) {
                throw VIPException::COMPANY_NAME_EXIST();
            }

            $isUseCode = $this->getVIPCompanyDao()->getByCode($fields['code']);
            if (!empty($isUseCode)) {
                throw VIPException::COMPANY_CODE_EXIST();
            }

            return $this->getVIPCompanyDao()->create($fields);
        } else {
            if ($vipCompany['name'] !== $fields['name']) {
                $isUseName = $this->getVIPCompanyDao()->getByName($fields['name']);
                if (!empty($isUseName)) {
                    throw VIPException::COMPANY_NAME_EXIST();
                }
            }
            if ($vipCompany['code'] !== $fields['code']) {
                $isUseCode = $this->getVIPCompanyDao()->getByCode($fields['code']);
                if (!empty($isUseCode)) {
                    throw VIPException::COMPANY_CODE_EXIST();
                }
            }

            return $this->getVIPCompanyDao()->update($vipCompany['id'], $fields);
        }

    }

    /**
     * 审核企业认证
     * @param int $id
     * @param int $status
     * @param string $reason
     * @return false|int|null
     * @throws \CoreW\Dao\DaoException
     */
    public function checkCompany(int $id, int $status, string $reason = '')
    {
        $vipCompany = $this->getVIPCompanyDao()->get($id);
        if (empty($vipCompany)) {
            return false;
        }

        if (BizEnum::VIP_COMPANY_STATUS_OK === $status) {
            $this->getVIPDao()->update($vipCompany['userId'], [
                'role' => BizEnum::VIP_ROLE_COMPANY
            ]);
        }

        // TODO: 添加企业认证成功事件,推送审核结果
        return $this->getVIPCompanyDao()->update($vipCompany['id'], [
            'status' => $status,
            'reason' => $reason
        ]);
    }

    public function getCompanyIotConfig(int $companyId)
    {
        return $this->getVIPCompanyIotDao()->getByCompanyId($companyId);
    }

    public function getCompanyIotConfigByUserId(int $userId)
    {
        return $this->getVIPCompanyIotDao()->getByUserId($userId);
    }

    /**
     * 设置企业物联网配置
     * @param int $companyId
     * @param int $userId
     * @param array $config
     * @return int|mixed|null
     * @throws \CoreW\Dao\DaoException
     */
    public function setCompanyIotConfig(int $companyId, int $userId, array $config): ?int
    {
        $vipCompany = $this->getVIPCompanyDao()->get($companyId);
        if (empty($vipCompany)) {
            throw VIPException::NOTFOUND_VIP_COMPANY();
        }
        if ($vipCompany['userId'] != $userId) {
            throw VIPException::NOT_VIP_COMPANY_USER();
        }
        $config = ArrayToolkit::parts($config, [
                'appId',
                'appSecret',
                'host',
                'param',
                'api',
                'serviceType',
                'status',
                'companyId'
            ]
        );
        // TODO: 增强验证
        $iotConfig = $this->getVIPCompanyIotDao()->getByCompanyId($companyId);
        if (!empty($iotConfig)) {
            return intval($this->getVIPCompanyIotDao()->update($iotConfig['id'], $config));
        }

        $config['userId'] = $vipCompany['userId'];
        $config['companyId'] = $vipCompany['id'];
        $this->getVIPCompanyIotDao()->create($config);
        return 1;
    }

    public function searchCompanyList(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        return $this->getVIPCompanyDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    public function getVIPById($id)
    {
        return $this->getVIPDao()->get($id);
    }

    /**
     * @param $uuid
     * @return array|null
     */
    public function getVIPByUUID($uuid)
    {
        $vip = $this->getVIPDao()->getByUUID($uuid);
        if (empty($vip)) {
            throw VIPException::NOTFOUND_USER();
        }

        $profile = $this->getProfileDao()->get($vip['id']);

        return array_merge($vip, $profile);
    }

    public function getVIPByNickname(string $nickname): ?array
    {
        $vip = $this->getVIPDao()->getByNickname($nickname);
        if (empty($vip)) {
            return null;
        }

        $profile = $this->getProfileDao()->get($vip['id']);

        return array_merge($vip, $profile);
    }

    public function countVIPs(array $conditions)
    {
        return $this->getVIPDao()->count($conditions);
    }

    public function searchVIPs(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        return $this->getVIPDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    /**
     *
     * 会员注册
     *
     * @param array $fields
     * @return array|null
     */
    public function register(array $fields)
    {
        $vip = $this->createVIP($fields);
        if (empty($vip)) {
            throw VIPException::REGISTER_VIP_ERROR();
        }

        // 推送邮箱验证通知 job
        Client::send('vip-email-verify-notification', $vip);

        return $vip;
    }

    public function createSystemVIP(string $nickname)
    {
        $password = substr($this->getRandomChar(), 0, 6);
        $vip = $this->createVIP([
            'nickname' => $nickname,
            'truename' => '内部会员_' . $nickname,
            'avatar' => '',
            'gender' => 'secret',
            'email' => $this->generateEmail(),
            'password' => $password
        ]);
        if ($vip) {
            return [
                'password' => $password
            ];
        }

        return null;
    }


    /**
     * 发送邮箱验证通知
     *
     * @param $vip
     * @return bool
     */
    public function sendEmailVerifyNotification($vip)
    {
        $user = $vip['nickname'] . "({$vip['email']})";
        try {
            $token = $this->makeToken('email-verify', [
                'vipId' => $vip['id'],
//            'duration' => 60,
                'expired_time' => strtotime('+1 day'),
                'data' => $vip['email']
            ]);
            $nickname = $vip['nickname'];
            $verifyUrl = AssetHelper::absoluteUrl('api/v1/email-verify?token=' . $token['token']);
            $mailOptions = [
                'to' => $vip['email'],
                'toName' => $nickname,
                'template' => 'email_vip_verify_email',
                'params' => [
                    'vip_name' => $nickname,
                    'verify_url' => $verifyUrl
                ]
            ];
            $mailFactory = $this->bfw->offsetGet('mail_factory');
            /** @var $mail AbstractMail */
            $mail = $mailFactory($mailOptions);
            $mail->send();
            $this->getSystemLogService()->info('VIP', 'email_verify_notification', '成功推送会员：' . $user . '邮箱验证通知');

            return true;
        } catch (\Throwable $e) {
            $this->getSystemLogService()->error('VIP', 'email_verify_notification', '失败推送会员：' . $user . '邮箱验证通知，' . $e->getMessage());

            return false;
        }
    }

    public function getUserByUUID($uuid)
    {
        return $this->getVIPDao()->getByUUID($uuid);
    }

    public function generateUUID()
    {
        $uuid = Uuid::uuid4();
        $user = $this->getUserByUUID($uuid);

        if (empty($user)) {
            return $uuid;
        } else {
            return $this->generateUUID();
        }
    }

    /**
     * @param array $fields
     * @return |null
     */
    public function createVIP(array $fields)
    {
        $validateFields = $this->validateFields($fields);
        v::callback(function ($value) {
            return $this->validateNicknameNotExist($value);
        })->setTemplate('用户名已被使用，请重新填写')->check($validateFields['nickname']);
        v::callback(function ($value) {
            return $this->validateEmailNotExist($value);
        })->setTemplate('邮箱已被使用，请重新填写')->check($validateFields['email']);
        // 手机号提供，需要验证是否绑定
        if (!empty($validateFields['phone'])) {
            v::callback(function ($value) {
                return $this->validatePhoneNotExist($value);
            })->setTemplate('手机号已被使用，请重新填写')->check($validateFields['phone']);
        }
        // 系统邀请码提供，需要验证是否绑定
        if (!empty($validateFields['invite_code'])) {
            v::callback(function ($value) {
                return $this->validateInviteCodeNotExist($value);
            })->setTemplate('系统推荐码已被使用，请重新填写')->check($validateFields['invite_code']);
        }
        $salt = StringToolkit::makeSalt();
        $data = [
            'nickname' => $fields['nickname'],
            'anonymous' => BizEnum::ENABLED,
            'inviteCode' => $fields['invite_code'] ?? '',
            'phone' => $fields['phone'] ?? '',
            'avatar' => $fields['avatar'] ?? '',
            'email' => $fields['email'],
            'uuid' => $this->generateUUID(),
            'status' => BizEnum::ENABLED,
            'integral' => config('app.api.register_user_send_integral'),
            'spaceSize' => config('app.api.register_user_send_space_size'),
            'salt' => $salt,
            'password' => StringToolkit::getPasswordEncoder()->hash($fields['password'], $salt)
        ];
        $requestIp = $fields['request_ip'] ?? '127.0.0.1';
        $this->beginTransaction();
        try {
            $vip = $this->getVIPDao()->create($data);
            $this->createVIPProfile($vip, $fields);
            $this->getSystemLogService()->info('VIP', 'add_vip', '新增会员成功', [
                'currentIp' => $requestIp
            ]);
            $this->commit();
            return $vip;
        } catch (\Throwable $e) {
            $this->rollback();
            $this->getSystemLogService()->info('VIP', 'add_vip', '新增会员失败，' . $e->getMessage(), [
                'currentIp' => $requestIp
            ]);
            return null;
        }
    }

    public function updateVIP($id, array $fields)
    {
    }

    public function editVIPInfo($uuid, array $fields)
    {
        $vip = $this->getVIPByUUID($uuid);
        if (empty($vip)) {
            throw VIPException::NOTFOUND_USER();
        }
        if (!$vip['status']) {
            throw VIPException::LOCKED_USER();
        }

        $fields = ArrayToolkit::parts($fields, ['nickname', 'avatar', 'intro', 'phone', 'email', 'password']);
        $profileFields = [];
        if (!empty($fields['nickname'])) {
            if ($vip['nickname'] !== $fields['nickname'] && !$this->validateNicknameNotExist($fields['nickname'])) {
                throw VIPException::NICKNAME_EXISTED();
            }
        }

        if (!empty($fields['phone'])) {
            if (!SimpleValidator::mobile($fields['phone'])) {
                throw VIPException::MOBILE_INVALID();
            }

            if ($vip['phone'] !== $fields['phone'] && !$this->validatePhoneNotExist($fields['phone'])) {
                throw VIPException::MOBILE_EXISTED();
            }
            $profileFields['mobile'] = $fields['phone'];
        }

        if (!empty($fields['email'])) {
            if (!SimpleValidator::email($fields['email'])) {
                throw VIPException::EMAIL_INVALID();
            }

            if ($vip['email'] !== $fields['email'] && !$this->validateEmailNotExist($fields['email'])) {
                throw VIPException::EMAIL_EXISTED();
            }
        }

        if (!empty($fields['password'])) {
            if (!SimpleValidator::password($fields['password'])) {
                throw VIPException::PASSWORD_INVALID();
            }

            $salt = StringToolkit::makeSalt();
            $fields['password'] = StringToolkit::getPasswordEncoder()->hash($fields['password'], $salt);
            $fields['salt'] = $salt;
        }

        $this->beginTransaction();
        try {
            if (!empty($profileFields)) {
                $this->getProfileDao()->update($vip['id'], $profileFields);
            }
            $this->getVIPDao()->update($vip['id'], $fields);
            $this->commit();
            return true;
        } catch (\Throwable $e) {
            $this->rollback();
            $params = null;
            if (!empty($fields['requestIp'])) {
                $params = [
                    'currentIp' => $fields['requestIp']
                ];
            }

            $this->getSystemLogService()->info('VIP', 'edit_vip_info', '会员修改个人失败，' . $e->getMessage(), $params);
            return false;
        }

    }

    /**
     * @param $id
     * @return bool
     */
    public function deleteVIPById($id)
    {
        return $this->getVIPDao()->delete($id);
    }

    /**
     * 邮箱验证
     *
     * @param $token
     * @return array|bool|int|null
     * @throws \CoreW\Dao\DaoException
     */
    public function emailVerify($token)
    {
        $effectiveToken = $this->getTokenService()->verifyToken('email-verify', $token);
        if (!$effectiveToken) {
            return -1;
        }

        $vip = $this->getVIPDao()->getByEmail($effectiveToken['data']);
        if (empty($vip)) {
            return -2;
        }

        // 有效更新用户字段
        $res = $this->getVIPDao()->update($vip['id'], ['emailVerified' => BizEnum::YES]);
        if ($res) {
            $this->getTokenService()->destroyToken($token);
        } else {
            return -1;
        }

        return $res;
    }


    /**
     * 会员登录
     *
     * @param LoginFormDto $dto
     * @return array
     */
    public function login(LoginFormDto $dto)
    {
        if (empty($dto->username)) {
            throw VIPException::PASSWORD_ERROR();
        }

        $user = null;
        if ($dto->mode === 'default') {
            if (empty($dto->password)) {
                throw VIPException::PASSWORD_ERROR();
            }
            $user = $this->getVIPDao()->getByNickname($dto->username);
            if (empty($user)) {
                $user = $this->getVIPDao()->getByEmail($dto->username);
                if (empty($user)) {
                    $user = $this->getVIPDao()->getByPhone($dto->username);
                }
            }
        } elseif ($dto->mode === 'email_code') {
            if (!SimpleValidator::email($dto->username)) {
                throw VIPException::LOGIN_EMAIL_CODE_USERNAME_ERROR();
            }

            if (empty($dto->verifyCode) || empty($dto->verifyKey)) {
                throw VIPException::LOGIN_EMAIL_CODE_EMPTY();
            }

            $this->validateEmailCode($dto->username, $dto->verifyKey, $dto->verifyCode);
            $user = $this->getVIPDao()->getByEmail($dto->username);
        } elseif ($dto->mode === 'oauth2_qq') {
            $user = $this->qqOauthLogin($dto);
        }

        if (empty($user)) {
            throw VIPException::PASSWORD_FAILED();
        }

        if ('default' === $dto->mode && !$this->verifyInSaltOut($dto->password, $user['salt'], $user['password'])) {
            // TODO: 登录保护：输错5次锁定用户
            throw VIPException::PASSWORD_ERROR();
        }


        if ($user['status'] == BizEnum::DISABLED) {
            throw VIPException::LOCKED_USER();
        }

        $currentUser = new CurrentUser();
        $user['currentIp'] = $dto->requestIp;
        $token = $this->makeAuthToken($dto->clientType, $user['id']);
        $currentUser->fromArray($user);
        $this->bfw->offsetSet('vip', $currentUser);

        return [$user, $token];
    }

    /**
     * @param LoginFormDto $dto
     * @return null|array
     * @throws \CoreW\Oauth2\Client\Oauth2Exception
     */
    protected function qqOauthLogin(LoginFormDto $dto)
    {
        if (empty($dto->oauth2Code)) {
            throw VIPException::LOGIN_OAUTH2_CODE_NULL_ERROR();
        }

        if (empty($dto->oauth2RedirectUri)) {
            throw VIPException::LOGIN_OAUTH2_REDIRECT_URI_NULL_ERROR();
        }

        $user = null;
        /** @var $oauth2QQ QQOauthClient */
        $oauth2QQ = $this->bfw->offsetGet('oauth2_qq');
        $qqAuth = $oauth2QQ->getAccessToken($dto->oauth2Code, $dto->oauth2RedirectUri);
        if (empty($qqAuth['userInfo'])) {
            throw  VIPException::LOGIN_OAUTH2_ERROR();
        }
        $bindType = BizEnum::OAUTH_CLIENT_QQ;
        $userBind = $this->getUserBindByTypeAndFromId($bindType, $qqAuth['userInfo']['id']);
        if (empty($userBind)) {
            $this->beginTransaction();
            try {
                $user = $this->createVIP([
                    'nickname' => $this->generateNickname('qq'),
                    'truename' => $qqAuth['userInfo']['name'],
                    'avatar' => $qqAuth['userInfo']['avatar'],
                    'gender' => $qqAuth['userInfo']['gender'],
                    'email' => $this->generateEmail(),
                    'password' => 'Aa@12345678'
                ]);
                $this->bindVIP($bindType, $qqAuth['userInfo']['id'], $user['id'], $qqAuth);
                $this->commit();
            } catch (\Throwable $e) {
                $this->rollback();
            }
        } else {
            $this->beginTransaction();
            try {
                $user = $this->getVIPById($userBind['toId']);
                if (!empty($user)) {
                    $this->getVIPDao()->update($user['id'], [
                        'avatar' => $qqAuth['userInfo']['avatar'],
                    ]);
                    $this->getProfileDao()->update($user['id'], [
                        'truename' => $qqAuth['userInfo']['name'],
                        'gender' => $qqAuth['userInfo']['gender'],
                    ]);
                }
                $this->commit();
            } catch (\Throwable $e) {
                $this->rollback();
            }
        }

        return $user;
    }

    public function verifyInSaltOut($in, $salt, $out)
    {
        return $out == $this->getPasswordEncoder()->hash($in, $salt);
    }

    /**
     * 生成token
     * @param $type
     * @param array $args
     * @return array
     */
    public function makeToken($type, $args = [])
    {
        return $this->getTokenService()->makeToken($type, $args);
    }

    /**
     * 销毁 token
     * @param $token
     * @return mixed
     */
    public function destroyToken($token)
    {
        return $this->getAuthTokenHandler()->destroyToken($token);
    }

    /**
     * 生成 登录认证 token
     *
     * @param $type
     * @param $vipId
     * @param null $duration
     * @param null $data
     * @param array $args
     * @return array
     */
    public function makeAuthToken($type, $vipId, $duration = null, $data = null, $args = [])
    {
        $token = [];
        $token['vipId'] = $vipId ? (int)$vipId : 0;
        $token['data'] = $data;
        $token['times'] = empty($args['times']) ? 0 : (int)($args['times']);
        $token['duration'] = $duration === null ? config('app.api.auth_ttl') : $duration;
        $result = $this->getAuthTokenHandler()->makeToken($type, $token);
        if ($result) {
            $result['expiredTime'] = intval($result['expiredTime']);
            return ArrayToolkit::parts($result, ['token', 'type', 'expiredTime']);
        }

        return null;
    }


    public function deleteUserBindByUserId($userId)
    {
        return $this->getVIPBindDao()->deleteByToId($userId);
    }

    public function getUserBindByTypeAndFromId($type, $fromId)
    {
        $type = $this->convertOAuthType($type);

        return $this->getVIPBindDao()->getByTypeAndFromId($type, $fromId);
    }

    public function unBindUserByTypeAndToId($type, $toId)
    {
        $user = $this->getVIPDao()->get($toId);

        if (empty($user)) {
            $this->createNewException(VIPException::NOTFOUND_USER());
        }

        if (!$this->typeInOAuthClient($type)) {
            $this->createNewException(VIPException::CLIENT_TYPE_INVALID());
        }

        $bind = $this->getUserBindByTypeAndUserId($type, $toId);
        if ($bind) {
            $convertedType = $this->convertOAuthType($type);
            $this->getVIPBindDao()->deleteByTypeAndToId($convertedType, $toId);
            $this->dispatchEvent('vip.unbind', new Event($user, ['bind' => $bind, 'bindType' => $type, 'convertedType' => $convertedType]));
            $this->getSystemLogService()->info('vip', 'unbind', sprintf('用户名%s解绑成功，操作用户为%s', $user['nickname']));
        }

        return $bind;
    }

    public function findBindsByUserId($userId)
    {
        $user = $this->getVIPDao()->get($userId);

        if (empty($user)) {
            $this->createNewException(VIPException::NOTFOUND_USER());
        }

        return $this->getVIPBindDao()->findByToId($userId);
    }

    public function findUserBindByTypeAndFromIds($type, $fromIds)
    {
        $type = $this->convertOAuthType($type);

        return $this->getVIPBindDao()->findByTypeAndFromIds($type, $fromIds);
    }

    public function findUserBindByTypeAndToIds($type, $toIds)
    {
        $type = $this->convertOAuthType($type);

        return $this->getVIPBindDao()->findByTypeAndToIds($type, $toIds);
    }

    public function getUserBindByToken($token)
    {
        return $this->getVIPBindDao()->getByToken($token);
    }

    public function getUserBindByTypeAndUserId($type, $toId)
    {
        $user = $this->getVIPDao()->get($toId);

        if (empty($user)) {
            $this->createNewException(VIPException::NOTFOUND_USER());
        }

        if (!$this->typeInOAuthClient($type)) {
            $this->createNewException(VIPException::CLIENT_TYPE_INVALID());
        }

        $type = $this->convertOAuthType($type);

        return $this->getVIPBindDao()->getByToIdAndType($type, $toId);
    }

    public function findUserBindByTypeAndUserId($type, $toId)
    {
        $user = $this->getVIPDao()->get($toId);

        if (empty($user)) {
            $this->createNewException(VIPException::NOTFOUND_USER());
        }

        $type = $this->convertOAuthType($type);

        return $this->getVIPBindDao()->findByToIdAndType($type, $toId);
    }

    public function bindVIP($type, $fromId, $toId, $token = [])
    {
        $user = $this->getVIPDao()->get($toId);

        if (empty($user)) {
            $this->createNewException(VIPException::NOTFOUND_USER());
        }

        if (!$this->typeInOAuthClient($type)) {
            $this->createNewException(VIPException::CLIENT_TYPE_INVALID());
        }

        $convertedType = $this->convertOAuthType($type);

        $bind = $this->getVIPBindDao()->create([
            'type' => $convertedType,
            'fromId' => $fromId,
            'toId' => $toId,
            'token' => empty($token['token']) ? '' : $token['token'],
            'createdTime' => time(),
            'expiredTime' => empty($token['expiredTime']) ? 0 : $token['expiredTime'],
        ]);

        $this->dispatchEvent('vip.bind', new Event($user, ['bind' => $bind, 'bindType' => $type, 'convertedType' => $convertedType, 'token' => $token]));
    }

    private function convertOAuthType($type): string
    {
        return strpos($type, 'wechat_') !== false ? 'wechat' : $type;
    }

    protected function typeInOAuthClient($type)
    {
        return in_array($type, array_keys(OauthFactory::CLIENTS));
    }

    public function generateNickname($rawNickname = '', $maxLoop = 100)
    {
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
            $rawNickname = 'vip';
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

    public function generateEmail($maxLoop = 100, $ext = '@vr.com.cn')
    {
        for ($i = 0; $i < $maxLoop; ++$i) {
            $email = 'vip_' . substr($this->getRandomChar(), 0, 9) . $ext;

            if ($this->isEmailAvaliable($email)) {
                break;
            }
        }

        return $email;
    }

    public function isNicknameAvaliable($nickname)
    {
        if (empty($nickname)) {
            return false;
        }

        $user = $this->getVIPDao()->getByNickname($nickname);

        return empty($user);
    }

    public function isEmailAvaliable($email)
    {
        if (empty($email)) {
            return false;
        }

        $user = $this->getVIPDao()->getByEmail($email);

        return empty($user);
    }

    /**
     * 创建会员资料信息
     *
     * @param $vip
     */
    protected function createVIPProfile($vip, $fields = [])
    {
        $defaultFields = [
            'mobile' => '',
            'idcard' => '',
            'truename' => '',
            'company' => '',
            'weixin' => '',
            'wechat_nickname' => '',
            'wechat_picture' => '',
            'gender' => 'secret',
        ];
        $profile = [];
        $profile['id'] = $vip['id'];
        foreach ($defaultFields as $attr => $defaultValue) {
            $profile[$attr] = $fields[$attr] ?? $defaultValue;
        }

        if (isset($vip['phone'])) {
            $profile['mobile'] = $vip['phone'];
        }

        $this->getProfileDao()->create($profile);
    }

    /**
     * @return TokenHandlerInterface
     */
    protected function getAuthTokenHandler()
    {
        return $this->bfw->offsetGet('api_auth')();
    }

    protected function validateNicknameNotExist($value)
    {
        $vip = $this->getVIPDao()->getByNickname($value);

        return empty($vip);
    }

    protected function validateEmailNotExist($value)
    {
        $vip = $this->getVIPDao()->getByEmail($value);

        return empty($vip);
    }

    protected function validatePhoneNotExist($value)
    {
        $vip = $this->getVIPDao()->getByPhone($value);

        return empty($vip);
    }

    protected function validateInviteCodeNotExist($value)
    {
        $vip = $this->getVIPDao()->getByInviteCode($value);

        return empty($vip);
    }

    /**
     * 生成会员通用字段验证
     *
     * @param $fields
     * @return array
     */
    protected function validateFields($fields)
    {
        $rules = [
            'nickname' => v::notEmpty()->setName('用户名'),
            'email' => v::email()->setName('邮箱'),
            'password' => v::callback(function ($value) {
                return SimpleValidator::lowPassword($value);
            })->setTemplate('密码要求5-20位字符串'),
            'check_pwd' => v::callback(function ($value) use ($fields) {
                return $fields['password'] !== $value;
            })->setTemplate('两次输入密码不一致'),
        ];

        if (!empty($fields['invite_code'])) {
            $rules['invite_code'] = v::stringVal()->setName('系统邀请码');
        }

        if (!empty($fields['phone'])) {
            $rules['phone'] = v::phone()->setName('手机');
        }

        return v::input($fields, $rules);
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

    /**
     * 验证邮箱登录验证码
     *
     * @param string $account 登录邮箱
     * @param string $key 验证码key
     * @param string $code 输入的验证码
     * @return bool
     */
    protected function validateEmailCode($account, $key, $code)
    {
        $cache = Redis::get($key);
        if (empty($cache)) {
            throw VIPException::LOGIN_EMAIL_CODE_EXPIRED();
        }

        $value = $account . '_' . $code;
        if ($cache !== $value) {
            throw VIPException::LOGIN_EMAIL_CODE_ERROR();
        }

        return true;
    }

    /**
     * @return VIPCompanyDao
     */
    protected function getVIPCompanyDao()
    {
        return $this->createDao('VIP:VIPCompanyDao');
    }

    /**
     * @return VIPCompanyIotDao
     */
    protected function getVIPCompanyIotDao()
    {
        return $this->createDao('VIP:VIPCompanyIotDao');
    }

    /**
     * @return VIPDao
     */
    protected function getVIPDao()
    {
        return $this->createDao('VIP:VIPDao');
    }

    /**
     * @return VIPProfileDao
     */
    protected function getProfileDao()
    {
        return $this->createDao('VIP:VIPProfileDao');
    }

    /**
     * @return VIPBindDao
     */
    protected function getVIPBindDao()
    {
        return $this->createDao('VIP:VIPBindDao');
    }

    /**
     * @return SystemLogService
     */
    protected function getSystemLogService()
    {
        return $this->createService('SystemLog:SystemLogService');
    }

    /**
     * @return TokenService
     */
    protected function getTokenService()
    {
        return $this->createService('VIP:TokenService');
    }
}
