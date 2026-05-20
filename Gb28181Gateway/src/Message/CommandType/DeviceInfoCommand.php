<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

class DeviceInfoCommand extends BaseCommand
{
    public function getCommandType() : string
    {
        return 'DeviceInfo';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []) : mixed
    {
        return [
            'device_id'   => $deviceId,
            'cmd_type'    => $this->getCommandType(),
            'device_info' => [
                'DeviceID'     => (string)($xml->DeviceID ?? ''),
                'DeviceName'   => (string)($xml->DeviceName ?? ''),
                'Manufacturer' => (string)($xml->Manufacturer ?? ''),
                'Model'        => (string)($xml->Model ?? ''),
                'Firmware'     => (string)($xml->Firmware ?? ''),
                'Channel'      => (string)($xml->Channel ?? ''),
            ],
        ];
    }

    public function generateResponse(array $data, int $sn) : string
    {
        return $this->generateXml('Response', [
            'CmdType'      => $this->getCommandType(),
            'SN'           => $sn,
            'DeviceID'     => $data['device_id'] ?? '',
            'Result'       => 'OK',
            'DeviceName'   => $data['name'] ?? '',
            'Manufacturer' => $data['manufacturer'] ?? '',
            'Model'        => $data['model'] ?? '',
            'Firmware'     => 'v1.0.0',
            'Channel'      => count($data['channels'] ?? []),
        ]);
    }
}