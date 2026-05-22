<?php

namespace CoreW\Business\Alarm\Enums;

/**
 * GB28181 报警级别枚举
 */
enum AlarmLevelEnum: int
{
    case LEVEL_1 = 1;  // 1级 - 一般
    case LEVEL_2 = 2;  // 2级 - 重要
    case LEVEL_3 = 3;  // 3级 - 紧急
    case LEVEL_4 = 4;  // 4级 - 特急

    public function label() : string
    {
        return match ($this) {
            self::LEVEL_1 => '1级',
            self::LEVEL_2 => '2级',
            self::LEVEL_3 => '3级',
            self::LEVEL_4 => '4级',
        };
    }

    public function getText() : string
    {
        return match ($this) {
            self::LEVEL_1 => '一般',
            self::LEVEL_2 => '重要',
            self::LEVEL_3 => '紧急',
            self::LEVEL_4 => '特急',
        };
    }

    public static function tryFromInt(?int $value) : ?self
    {
        if ($value === null) {
            return null;
        }
        return self::tryFrom($value);
    }

    /**
     * 获取所有选项
     * @return array
     */
    public static function options() : array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[] = [
                'value' => $case->value,
                'label' => $case->label(),
                'text'  => $case->getText(),
            ];
        }
        return $options;
    }
}
