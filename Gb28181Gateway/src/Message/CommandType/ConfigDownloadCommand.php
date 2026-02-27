<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

/**
 * 设备配置查询响应处理
 *
 * 解析设备返回的配置数据 XML:
 * <Response>
 *   <CmdType>ConfigDownload</CmdType>
 *   <SN>...</SN>
 *   <DeviceID>...</DeviceID>
 *   <Result>OK</Result>
 *   <BasicParam>
 *     <Name>...</Name>
 *     <DeviceID>...</DeviceID>
 *     <SIPServerID>...</SIPServerID>
 *     <Expiration>...</Expiration>
 *     <HeartBeatInterval>...</HeartBeatInterval>
 *     <HeartBeatCount>...</HeartBeatCount>
 *   </BasicParam>
 * </Response>
 */
class ConfigDownloadCommand extends BaseCommand
{
    public function getCommandType(): string
    {
        return 'ConfigDownload';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): mixed
    {
        $basicParam = [];

        if (isset($xml->BasicParam)) {
            $bp = $xml->BasicParam;
            $basicParam = [
                'Name' => (string) ($bp->Name ?? ''),
                'DeviceID' => (string) ($bp->DeviceID ?? ''),
                'SIPServerID' => (string) ($bp->SIPServerID ?? ''),
                'SIPServerIP' => (string) ($bp->SIPServerIP ?? ''),
                'SIPServerPort' => (string) ($bp->SIPServerPort ?? ''),
                'DomainName' => (string) ($bp->DomainName ?? ''),
                'Expiration' => (string) ($bp->Expiration ?? ''),
                'Password' => (string) ($bp->Password ?? ''),
                'HeartBeatInterval' => (string) ($bp->HeartBeatInterval ?? ''),
                'HeartBeatCount' => (string) ($bp->HeartBeatCount ?? ''),
            ];
        }

        return [
            'device_id' => $deviceId,
            'cmd_type' => $this->getCommandType(),
            'result' => (string) ($xml->Result ?? ''),
            'basic_param' => $basicParam,
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
