<?php


namespace app\api\filters;


use CoreW\Business\BizEnum;
use CoreW\Business\DataFilters\Filter;
use support\utils\AssetHelper;

class VIPFilter extends Filter
{
    protected $simpleFields = [
        'id',
        'uuid',
        'destroyed',
        'nickname',
        'email',
        'avatar',
        'avatar_full',
        'currentIp',
        'phone',
        'loginTime',
        'createdTime',
        'loginIp',
        'status',
        'truename',
        'gender',
        'birthday',
        'intro',
        'weixin',
        'qq',
        'wechat_nickname',
        'wechat_picture',
        'role',
        'role_text'
    ];

    protected $publicFields = [
        'uuid',
        'nickname',
        'email',
        'phone',
        'avatar',
        'avatar_full',
    ];


    protected $mode = self::SIMPLE_MODE;

    protected function simpleFields(&$data)
    {
        if (isset($data['gender'])) {
            $data['gender_text'] = BizEnum::getUserGenderItems($data['gender']);
        }

        $this->transformAvatar($data);
        $this->destroyedNicknameFilter($data);
        $data['role_text'] = BizEnum::getVipRoleItems($data['role']);
    }

    protected function publicFields(&$data)
    {
        $data['email'] = '*****';
        if (!empty($data['phone'])) {
            $data['phone'] = substr_replace($data['phone'], '****', 3, 4);
        }
        $this->transformAvatar($data);
    }

    private function transformAvatar(&$data)
    {
        $data['avatar_full'] = AssetHelper::getUploadUrl($data['avatar'], 'avatar');

        $data['company_banner_cover'] = AssetHelper::getUploadUrl('', 'person-banner');
    }

    protected function destroyedNicknameFilter(&$data)
    {
        $data['nickname'] = (1 == $data['destroyed']) ? '帐号已注销' : $data['nickname'];
    }

}