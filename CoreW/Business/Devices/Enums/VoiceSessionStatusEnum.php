<?php

namespace CoreW\Business\Devices\Enums;

enum VoiceSessionStatusEnum: string
{
    case WAITING_STREAM = 'waiting_stream';
    case STREAM_ARRIVED = 'stream_arrived';
    case INVITING = 'inviting';
    case CONNECTED = 'connected';
    case ENDED = 'ended';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::WAITING_STREAM => '等待语音推流',
            self::STREAM_ARRIVED => '语音推流已到达',
            self::INVITING => '网关信令已发送',
            self::CONNECTED => '已连接',
            self::ENDED => '已结束',
            self::FAILED => '失败',
        };
    }

    /**
     * 检查是否为终态（不能再转换）
     */
    public function isTerminal(): bool
    {
        return $this === self::ENDED || $this === self::FAILED;
    }

    /**
     * 检查是否为活跃状态
     */
    public function isActive(): bool
    {
        return !$this->isTerminal();
    }
}
