<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

class KeepaliveCommand extends BaseCommand
{
    public function getCommandType(): string
    {
        return 'Keepalive';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): mixed
    {
        // For keepalive, we just need to respond with status OK
        $status = (string)($xml->Status ?? 'OK');
        
        return [
            'device_id' => $deviceId,
            'status' => $status,
            'cmd_type' => $this->getCommandType()
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