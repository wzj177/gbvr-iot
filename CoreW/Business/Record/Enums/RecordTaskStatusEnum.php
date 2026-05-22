<?php

namespace CoreW\Business\Record\Enums;

enum RecordTaskStatusEnum: string
{
    case PENDING = 'pending';
    case INVITING = 'inviting';
    case WAIT_STREAM = 'wait_stream';

    case RECORDING = 'recording';
    case FINALIZING = 'finalizing';

    case DONE = 'done';

    case FAILED = 'failed';

    case CANCELLED = 'cancelled';

    public function label() : string
    {
        return match ($this) {
            self::PENDING => '待执行',
            self::INVITING => 'INVITE中',
            self::WAIT_STREAM => '等待流',
            self::RECORDING => '录像中',
            self::FINALIZING => '完成中',
            self::DONE => '已完成',
            self::FAILED => '失败',
            self::CANCELLED => '已取消',
        };
    }

    public static function getItems() : array
    {
        return array_map(fn($item) => [
            'key'   => $item->value,
            'value' => $item->label(),
        ], self::cases());
    }

    public function isFinal() : bool
    {
        return match ($this) {
            self::DONE, self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }

    public function isProcessing() : bool
    {
        return match ($this) {
            self::INVITING, self::WAIT_STREAM, self::RECORDING, self::FINALIZING => true,
            default => false,
        };
    }
}
