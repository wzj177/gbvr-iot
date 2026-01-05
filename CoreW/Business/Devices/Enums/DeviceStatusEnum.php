<?php

namespace CoreW\Business\Devices\Enums;

enum DeviceStatusEnum: string
{
    case ONLINE = 'online';
    case EXPIRED = 'expired';
    case UNREGISTERED = 'unregistered';


    public function getText(): string
    {
        return match ($this) {
            self::ONLINE => '在线',
            self::EXPIRED => '心跳超时',
            self::UNREGISTERED => '已注销',
            default => '未知',
        };
    }

    public static function getItems(): array
    {
        return array_map(fn($item) => [
            'key' => $item->value,
            'value' => $item->getText(),
        ], self::cases());
    }

    public static function getOptions(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getText();
        }
        return $options;
    }
}
