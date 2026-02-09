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
        'action',
        'message',
        'ip',
        'ipArea',
        'createdTime',
    ];

    protected $publicFields = [];

    protected array $appendFields = [
        'module_text' => 'appendModuleText',
        'action_text' => 'appendActionText',
        'level_text' => 'appendLevelText',
    ];

    public function publicFields(&$data): void
    {
        if (!empty($data['data']) && is_string($data['data'])) {
            $data['data'] = json_decode($data['data'], true);
        }
    }

    public function simpleFields(&$data): void
    {
        self::commonFields($data);
    }

    public function appendModuleText($data): string
    {
        return LogEnum::getModuleText($data['module']);
    }

    public function appendActionText($data): string
    {
        return LogEnum::getActionText($data['module'], $data['action']);
    }

    public function appendLevelText(&$data): string
    {
        return LogEnum::getLevelText($data['level']);
    }
}