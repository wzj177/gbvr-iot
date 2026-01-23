<?php

namespace app\admin\filters;

use CoreW\Business\Alarm\Enums\AlarmLevelEnum;
use CoreW\Business\Alarm\Enums\AlarmMethodEnum;
use CoreW\Business\DataFilters\Filter;

class AlarmEventFilter extends Filter
{
    protected array $publicFields = [];

    protected array $appendFields = [
        'level_text' => 'appendLevelText',
        'method_text' => 'appendMethodText',
        'alarm_time_format' => 'appendAlarmTimeFormat',
    ];

    protected function appendLevelText($data): string
    {
        if (!isset($data['level'])) {
            return '--';
        }

        $enum = AlarmLevelEnum::tryFromInt($data['level']);
        return $enum ? $enum->label() : '--';
    }

    protected function appendMethodText($data): string
    {
        if (!isset($data['method'])) {
            return '--';
        }

        $enum = AlarmMethodEnum::tryFromInt($data['method']);
        return $enum ? $enum->label() : '--';
    }

    protected function appendAlarmTimeFormat($data): string
    {
        if (!isset($data['alarm_time'])) {
            return '--';
        }

        return $data['alarm_time'];
    }

    /**
     * 格式化单条数据
     */
    public static function one(array $data): array
    {
        $filter = new self();
        return $filter->filter($data);
    }

    /**
     * 格式化列表数据
     */
    public static function list(array $list): array
    {
        return array_map(fn($item) => self::one($item), $list);
    }

    /**
     * 公开列表数据（去除敏感字段）
     */
    public static function publicList(array $list): array
    {
        return self::list($list);
    }
}
