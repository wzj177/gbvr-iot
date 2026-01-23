<?php

namespace CoreW\Business\Alarm\Enums;

use CoreW\Business\BaseEnum;

/**
 * GB28181 报警方式枚举
 */
enum AlarmMethodEnum: int
{
    case TELEPHONE = 1;          // 电话报警
    case DEVICE = 2;             // 设备报警
    case SMS = 3;                // 短信报警
    case GPS = 4;                // GPS 报警
    case VIDEO = 5;              // 视频报警
    case DEVICE_FAULT = 6;       // 设备故障报警
    case OTHER = 7;              // 其他报警

    public function label(): string
    {
        return match ($this) {
            self::TELEPHONE => '电话报警',
            self::DEVICE => '设备报警',
            self::SMS => '短信报警',
            self::GPS => 'GPS 报警',
            self::VIDEO => '视频报警',
            self::DEVICE_FAULT => '设备故障报警',
            self::OTHER => '其他报警',
        };
    }

    public static function tryFromInt(?int $value): ?self
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
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[] = [
                'value' => $case->value,
                'label' => $case->label(),
            ];
        }
        return $options;
    }
}
