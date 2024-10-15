<?php


namespace app\admin\filters;


use CoreW\Business\BizEnum;
use CoreW\Business\DataFilters\Filter;
use CoreW\Business\SystemLog\LogEnum;

class SystemLogFilter extends Filter
{
    protected $simpleFields = [
        'id',
        'level',
        'levelText',
        'userId',
        'userName',
        'module',
        'moduleText',
        'action',
        'actionText',
        'message',
        'ip',
        'ipArea',
        'createdTime',
    ];

    protected $publicFields = [
        'id',
        'level',
        'levelText',
        'userId',
        'userName',
        'module',
        'moduleText',
        'action',
        'actionText',
        'message',
        'ip',
        'ipArea',
        'createdTime',
        'data'
    ];

    public function publicFields(&$data):void
    {
        if (!empty($data['data']) && is_string($data['data'])) {
            $data['data'] = json_decode($data['data'], true);
        }
        self::commonFields($data);
    }

    public function simpleFields(&$data):void
    {
        self::commonFields($data);
    }

    protected function commonFields(&$data)
    {
        $data['moduleText'] = LogEnum::getModuleText($data['module']);
        $data['actionText'] = LogEnum::getActionText($data['module'], $data['action']);
        $data['levelText'] = LogEnum::getLevelText($data['level']);
    }
}