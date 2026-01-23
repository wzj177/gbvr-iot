<?php

namespace app\admin\filters;

use CoreW\Business\Alarm\Enums\AlarmLevelEnum;
use CoreW\Business\Alarm\Enums\AlarmMethodEnum;
use CoreW\Business\DataFilters\Filter;

class AlarmPlanFilter extends Filter
{
    protected array $publicFields = [];

    protected array $appendFields = [
        'status_text' => 'appendStatusText',
        'alarm_level_text' => 'appendAlarmLevelText',
        'alarm_method_text' => 'appendAlarmMethodText',
    ];

    protected function appendStatusText($data): string
    {
        if (!isset($data['status'])) {
            return '--';
        }

        return $data['status'] == 1 ? '启用' : '禁用';
    }

    protected function appendAlarmLevelText($data): string
    {
        if (!isset($data['alarm_level']) || empty($data['alarm_level'])) {
            return '--';
        }

        $levels = is_array($data['alarm_level']) ? $data['alarm_level'] : json_decode($data['alarm_level'], true);
        if (empty($levels)) {
            return '--';
        }

        $texts = [];
        foreach ($levels as $level) {
            $enum = AlarmLevelEnum::tryFromInt($level);
            if ($enum) {
                $texts[] = $enum->label();
            }
        }

        return implode(', ', $texts);
    }

    protected function appendAlarmMethodText($data): string
    {
        if (!isset($data['alarm_method']) || empty($data['alarm_method'])) {
            return '--';
        }

        $methods = is_array($data['alarm_method']) ? $data['alarm_method'] : json_decode($data['alarm_method'], true);
        if (empty($methods)) {
            return '--';
        }

        $texts = [];
        foreach ($methods as $method) {
            $enum = AlarmMethodEnum::tryFromInt($method);
            if ($enum) {
                $texts[] = $enum->label();
            }
        }

        return implode(', ', $texts);
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
