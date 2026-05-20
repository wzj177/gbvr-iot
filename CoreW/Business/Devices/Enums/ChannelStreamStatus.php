<?php

namespace CoreW\Business\Devices\Enums;

enum ChannelStreamStatus: string
{
    // 'idle','pushing','failed'
    case IDLE = 'idle';
    case PUSHING = 'pushing';
    case FAILED = 'failed';

    public static function values() : array
    {
        return [
            self::IDLE,
            self::PUSHING,
            self::FAILED,
        ];
    }

    public function label() : string
    {
        return match ($this) {
            self::IDLE => '空闲',
            self::PUSHING => '推流中',
            self::FAILED => '失败'
        };
    }

}