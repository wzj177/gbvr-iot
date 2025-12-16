<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

class AlarmCommand extends BaseCommand
{
    public function getCommandType(): string
    {
        return 'Alarm';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): mixed
    {
        return [
            'device_id' => $deviceId,
            'cmd_type' => $this->getCommandType(),
            'alarm_priority' => (string)($xml->AlarmPriority ?? ''),
            'alarm_method' => (string)($xml->AlarmMethod ?? ''),
            'alarm_time' => (string)($xml->AlarmTime ?? ''),
            'alarm_description' => (string)($xml->AlarmDescription ?? ''),
            'longitude' => (string)($xml->Longitude ?? ''),
            'latitude' => (string)($xml->Latitude ?? ''),
        ];
    }

    public function generateResponse(array $data, int $sn): string
    {
        return $this->generateXml('Response', [
            'CmdType' => $this->getCommandType(),
            'SN' => $sn,
            'DeviceID' => $data['device_id'] ?? '',
            'Result' => 'OK'
        ]);
    }
}