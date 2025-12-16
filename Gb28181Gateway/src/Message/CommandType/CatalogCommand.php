<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

class CatalogCommand extends BaseCommand
{
    public function getCommandType(): string
    {
        return 'Catalog';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): array
    {
        $sumNum = (int)($xml->SumNum ?? 0);
        $deviceList = [];
        
        if (isset($xml->DeviceList->Item)) {
            foreach ($xml->DeviceList->Item as $item) {
                $deviceList[] = [
                    'DeviceID' => (string)($item->DeviceID ?? ''),
                    'Name' => (string)($item->Name ?? ''),
                    'Manufacturer' => (string)($item->Manufacturer ?? ''),
                    'Model' => (string)($item->Model ?? ''),
                    'Owner' => (string)($item->Owner ?? ''),
                    'CivilCode' => (string)($item->CivilCode ?? ''),
                    'Block' => (string)($item->Block ?? ''),
                    'Address' => (string)($item->Address ?? ''),
                    'Parental' => (string)($item->Parental ?? ''),
                    'ParentID' => (string)($item->ParentID ?? ''),
                    'SafetyWay' => (string)($item->SafetyWay ?? ''),
                    'RegisterWay' => (string)($item->RegisterWay ?? ''),
                    'CertNum' => (string)($item->CertNum ?? ''),
                    'Certifiable' => (string)($item->Certifiable ?? ''),
                    'ErrCode' => (string)($item->ErrCode ?? ''),
                    'EndTime' => (string)($item->EndTime ?? ''),
                    'Secrecy' => (string)($item->Secrecy ?? ''),
                    'IPAddress' => (string)($item->IPAddress ?? ''),
                    'Port' => (string)($item->Port ?? ''),
                    'Password' => (string)($item->Password ?? ''),
                    'Status' => (string)($item->Status ?? ''),
                    'Longitude' => (string)($item->Longitude ?? ''),
                    'Latitude' => (string)($item->Latitude ?? ''),
                ];
            }
        }
        
        return [
            'device_id' => $deviceId,
            'sum_num' => $sumNum,
            'device_list' => $deviceList,
            'cmd_type' => $this->getCommandType()
        ];
    }

    public function generateResponse(array $data, int $sn): string
    {
        $response = [
            'CmdType' => $this->getCommandType(),
            'SN' => $sn,
            'DeviceID' => $data['device_id'] ?? '',
            'SumNum' => count($data['channels'] ?? []),
        ];

        if (isset($data['channels']) && !empty($data['channels'])) {
            $deviceList = [
                'DeviceList' => [
                    'Num' => count($data['channels']),
                ]
            ];

            foreach ($data['channels'] as $channel) {
                $deviceList['DeviceList'][] = [
                    'Item' => [
                        'DeviceID' => $channel['id'] ?? '',
                        'Name' => $channel['name'] ?? '',
                        'Manufacturer' => $data['manufacturer'] ?? '',
                        'Model' => $data['model'] ?? '',
                        'Owner' => '',
                        'CivilCode' => '',
                        'Block' => '',
                        'Address' => '',
                        'Parental' => '0',
                        'ParentID' => $data['device_id'] ?? '',
                        'SafetyWay' => '0',
                        'RegisterWay' => '1',
                        'CertNum' => '',
                        'Certifiable' => '0',
                        'ErrCode' => '0',
                        'EndTime' => '',
                        'Secrecy' => '0',
                        'IPAddress' => '',
                        'Port' => '',
                        'Password' => '',
                        'Status' => 'ON',
                        'Longitude' => '',
                        'Latitude' => '',
                    ]
                ];
            }

            $response = array_merge($response, $deviceList);
        }

        return $this->generateXml('Response', $response);
    }
}