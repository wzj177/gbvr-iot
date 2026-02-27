<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

/**
 * Broadcast 广播响应处理器
 *
 * 处理设备对 Broadcast MESSAGE 的响应：
 * <Response>
 *   <CmdType>Broadcast</CmdType>
 *   <SN>xxx</SN>
 *   <DeviceID>channelId</DeviceID>
 *   <Result>OK</Result>
 * </Response>
 *
 * 设备收到广播通知后发送此响应确认，随后设备会主动发送 INVITE。
 */
class BroadcastCommand extends BaseCommand
{
    public function getCommandType(): string
    {
        return 'Broadcast';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): mixed
    {
        return [
            'device_id' => $deviceId,
            'cmd_type' => $this->getCommandType(),
            'channel_id' => (string)($xml->DeviceID ?? ''),
            'result' => (string)($xml->Result ?? ''),
            'sn' => (string)($xml->SN ?? ''),
        ];
    }

    public function generateResponse(array $data, int $sn): string
    {
        return $this->generateXml('Response', [
            'CmdType' => $this->getCommandType(),
            'SN' => $sn,
            'DeviceID' => $data['device_id'] ?? '',
            'Result' => 'OK',
        ]);
    }
}
