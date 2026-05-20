<?php


namespace app\admin\filters;


use CoreW\Business\DataFilters\Filter;
use support\utils\AssetHelper;

class UserFilter extends Filter
{
    protected $simpleFields
        = [
            'id',
            'nickname',
            'email',
            'avatar',
            'uuid',
            'destroyed',
            'roles',
            'currentIp',
            'verifiedMobile',
            'loginTime',
            'createdTime',
            'loginIp',
            'truename',
            'locked',
        ];

    protected $publicFields
        = [
            'id',
            'nickname',
            'email',
            'avatar',
            'uuid',
        ];

    protected $authenticatedFields
        = [
            'id',
            'nickname',
            'uuid',
            'loginTime',
            'loginIp',
            'truename',
            'roles',
            'avatar',
        ];


    protected string $mode = self::SIMPLE_MODE;

    protected function simpleFields(&$data)
    {
        !empty($data['loginTime']) && $data['loginTime'] = date('c', $data['loginTime']);

        $this->transformAvatar($data);
        $this->destroyedNicknameFilter($data);
    }

    protected function publicFields(&$data)
    {
    }

    protected function authenticatedFields(&$data)
    {
        !empty($data['loginTime']) && $data['loginTime'] = date('c', $data['loginTime']);
        $data['email'] = '*****';
        if (!empty($data['verifiedMobile'])) {
            $data['verifiedMobile'] = substr_replace($data['verifiedMobile'], '****', 3, 4);
        } else {
            unset($data['verifiedMobile']);
        }
        $this->transformAvatar($data);
    }

    private function transformAvatar(&$data)
    {
        $data['avatar'] = AssetHelper::getUploadUrl($data['avatar'], 'avatar');

        unset($data['smallAvatar']);
        unset($data['mediumAvatar']);
        unset($data['largeAvatar']);
    }

    protected function destroyedNicknameFilter(&$data)
    {
        $data['nickname'] = (1 == $data['destroyed']) ? '帐号已注销' : $data['nickname'];
    }
}