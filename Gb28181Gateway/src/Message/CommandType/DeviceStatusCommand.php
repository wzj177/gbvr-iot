<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

class DeviceStatusCommand extends BaseCommand
{
    public function getCommandType(): string
    {
        return 'DeviceStatus';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): mixed
    {
        return [
            'device_id' => $deviceId,
            'cmd_type' => $this->getCommandType(),
            'online' => (string)($xml->Online ?? ''),
            'status' => (string)($xml->Status ?? ''),
            'reason' => (string)($xml->Reason ?? '')
        ];
    }

    public function generateResponse(array $data, int $sn): string
    {
        return $this->generateXml('Response', [
            'CmdType' => $this->getCommandType(),
            'SN' => $sn,
            'DeviceID' => $data['device_id'] ?? '',
            'Result' => 'OK',
            'Online' => $data['online'] ?? 'ONLINE',
            'Status' => $data['status'] ?? 'OK'
        ]);
    }
}