<?php

namespace CoreW\Business\VIP\Service;

use CoreW\Business\VIP\Dao\LoginFormDto;

interface VIPService
{
    public function getVIPById($id);

    public function countVIPs(array $conditions);

    public function searchVIPs(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function createVIP(array $fields);

    public function updateVIP($id, array $fields);

    public function editVIPInfo($uuid, array $fields);

    public function deleteVIPById($id);

    /**
     * 会员注册
     *
     * @param array $fields
     * @return array|null
     */
    public function register(array $fields);

    /**
     * 创建系统内部会员
     * @param string $nickname
     * @return mixed
     */
    public function createSystemVIP(string $nickname);

    /**
     * 生成会员认证token
     *
     * @param $type
     * @param $vipId
     * @param null $duration
     * @param null $data
     * @param array $args
     * @return array
     */
    public function makeAuthToken($type, $vipId, $duration = null, $data = null, $args = []);

    /**
     * 邮箱验证
     *
     * @param $token
     * @return array|bool|int|null
     * @throws \CoreW\Dao\DaoException
     */
    public function emailVerify($token);

    /**
     * 发送邮箱验证通知
     *
     * @param $vip
     * @return bool
     */
    public function sendEmailVerifyNotification($vip);

    /**
     * 销毁 token
     * @param $token
     * @return mixed
     */
    public function destroyToken($token);

    /**
     * 会员登录
     *
     * @param LoginFormDto $dto
     * @return array
     */
    public function login(LoginFormDto $dto) : array;


    /**
     * 获取会员信息
     *
     * @param $uuid
     * @return array|null
     */
    public function getVIPByUUID($uuid);

    public function getVIPByNickname(string $nickname) : ?array;

    /**
     * 给账号发送邮箱
     * @param $email
     * @param $expireTime int 过期时间（单位：s)
     * @return mixed|string
     */
    public function sendAccountEmailLoginCode($email, $expireTime = 600);

    public function deleteUserBindByUserId($userId);

    public function getUserBindByTypeAndFromId($type, $fromId);

    public function unBindUserByTypeAndToId($type, $toId);

    public function findBindsByUserId($userId);

    public function findUserBindByTypeAndFromIds($type, $fromIds);

    public function findUserBindByTypeAndToIds($type, $toIds);

    public function getUserBindByToken($token);

    public function getUserBindByTypeAndUserId($type, $toId);

    public function findUserBindByTypeAndUserId($type, $toId);

    public function bindVIP($type, $fromId, $toId, $token);

    /**
     * 新增会员使用空间
     *
     * @param int $userId
     * @param int $fileSize
     * @return false|int|null
     * @throws \CoreW\Dao\DaoException
     */
    public function addUsedSpaceSize(int $userId, int $fileSize);

    /**
     * 扣减会员使用空间
     *
     * @param int $userId
     * @param int $fileSize
     * @return false|int|null
     * @throws \CoreW\Dao\DaoException
     */
    public function subUsedSpaceSize(int $userId, int $fileSize);

    /**
     * 提交企业认证
     * @param int $userId
     * @param array $fields
     * @return int|mixed|null
     * @throws \CoreW\Dao\DaoException
     */
    public function applyCompany(int $userId, array $fields);

    public function getVIPCompany(int $companyId);

    public function getVIPCompanyByUserId(int $userId);

    /**
     * 审核企业认证
     * @param int $id
     * @param int $status
     * @param string $reason
     * @return false|int|null
     * @throws \CoreW\Dao\DaoException
     */
    public function checkCompany(int $id, int $status, string $reason = '');

    /**
     * 设置企业物联网配置
     * @param int $companyId
     * @param int $userId
     * @param array $config
     * @return int|mixed|null
     * @throws \CoreW\Dao\DaoException
     */
    public function setCompanyIotConfig(int $companyId, int $userId, array $config);

    public function getCompanyIotConfig(int $companyId);

    public function searchCompanyList(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function getCompanyIotConfigByUserId(int $userId);

    public function getCompanyIotConfigByAppId(string $appId);
}
