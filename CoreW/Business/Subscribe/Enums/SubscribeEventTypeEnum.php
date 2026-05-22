<?php

namespace CoreW\Business\Subscribe\Enums;

enum SubscribeEventTypeEnum: string
{
    case CATALOG = 'catalog';
    case ALARM = 'alarm';
    case MOBILE_POSITION = 'mobile_position';

    public function label() : string
    {
        return match ($this) {
            self::CATALOG => '目录变更',
            self::ALARM => '报警',
            self::MOBILE_POSITION => '移动位置',
        };
    }

    public static function getItems() : array
    {
        return array_map(fn($item) => [
            'key'   => $item->value,
            'value' => $item->label(),
        ], self::cases());
    }

    public static function fromGb28181(string $gbType) : ?self
    {
        return match ($gbType) {
            'Catalog' => self::CATALOG,
            'Alarm' => self::ALARM,
            'MobilePosition' => self::MOBILE_POSITION,
            default => null,
        };
    }

    public function toGb28181() : string
    {
        return match ($this) {
            self::CATALOG => 'Catalog',
            self::ALARM => 'Alarm',
            self::MOBILE_POSITION => 'MobilePosition',
        };
    }
}
