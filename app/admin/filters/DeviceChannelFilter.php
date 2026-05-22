<?php

namespace app\admin\filters;

use CoreW\Business\DataFilters\Filter;
use CoreW\Business\Devices\Enums\ChannelStreamStatus;
use CoreW\Business\Devices\Enums\ChannelTypeEnum;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;

class DeviceChannelFilter extends Filter
{
    protected array $publicFields = [];

    protected array $appendFields
        = [
            'channel_type_text'  => 'appendChannelTypeText',
            'status_text'        => 'appendStatusText',
            'stream_status_text' => 'appendStreamStatusText',
        ];

    protected function appendChannelTypeText($data) : string
    {
        if (!isset($data['channel_type']) || 'unknown' === $data['channel_type']) {
            return '--';
        }
        $type = ChannelTypeEnum::tryFromInt((int)$data['channel_type']);

        return $type->label();
    }

    protected function appendStatusText($data) : string
    {
        if (!isset($data['status'])) {
            return '--';
        }

        $status = DeviceStatusEnum::tryFrom($data['status']);

        return $status->getText();

    }

    protected function appendStreamStatusText($data) : string
    {
        if (!isset($data['stream_status'])) {
            return '--';
        }
        $status = ChannelStreamStatus::tryFrom($data['stream_status']);

        return $status->label();

    }
}