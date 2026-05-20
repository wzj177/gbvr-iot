<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

class DeviceControlCommand extends BaseCommand
{
    public function getCommandType() : string
    {
        return 'DeviceControl';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []) : mixed
    {
        return [
            'device_id'    => $deviceId,
            'cmd_type'     => $this->getCommandType(),
            'control_type' => (string)($xml->ControlType ?? ''),
            'tele_boot'    => (string)($xml->TeleBoot ?? ''),
        ];
    }

    public function generateResponse(array $data, int $sn) : string
    {
        return $this->generateXml('Response', [
            'CmdType'  => $this->getCommandType(),
            'SN'       => $sn,
            'DeviceID' => $data['device_id'] ?? '',
            'Result'   => 'OK',
        ]);
    }
}