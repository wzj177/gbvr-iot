<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

class RecordInfoCommand extends BaseCommand
{
    public function getCommandType() : string
    {
        return 'RecordInfo';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []) : mixed
    {
        $sumNum = (int)($xml->SumNum ?? 0);
        $recordList = [];

        if (isset($xml->RecordList->Item)) {
            foreach ($xml->RecordList->Item as $item) {
                $recordList[] = [
                    'DeviceID'   => (string)($item->DeviceID ?? ''),
                    'Name'       => (string)($item->Name ?? ''),
                    'FilePath'   => (string)($item->FilePath ?? ''),
                    'Address'    => (string)($item->Address ?? ''),
                    'StartTime'  => (string)($item->StartTime ?? ''),
                    'EndTime'    => (string)($item->EndTime ?? ''),
                    'Secrecy'    => (string)($item->Secrecy ?? ''),
                    'Type'       => (string)($item->Type ?? ''),
                    'RecorderID' => (string)($item->RecorderID ?? ''),
                ];
            }
        }

        return [
            'device_id'   => $deviceId,
            'sum_num'     => $sumNum,
            'record_list' => $recordList,
            'cmd_type'    => $this->getCommandType(),
        ];
    }

    public function generateResponse(array $data, int $sn) : string
    {
        $response = [
            'CmdType'  => $this->getCommandType(),
            'SN'       => $sn,
            'DeviceID' => $data['device_id'] ?? '',
            'Name'     => $data['name'] ?? '',
            'SumNum'   => count($data['records'] ?? []),
        ];

        if (isset($data['records']) && !empty($data['records'])) {
            $recordList = [
                'RecordList' => [
                    'Num' => count($data['records']),
                ],
            ];

            foreach ($data['records'] as $record) {
                $recordList['RecordList'][] = [
                    'Item' => [
                        'DeviceID'   => $record['device_id'] ?? '',
                        'Name'       => $record['name'] ?? '',
                        'FilePath'   => $record['file_path'] ?? '',
                        'Address'    => $record['address'] ?? '',
                        'StartTime'  => $record['start_time'] ?? '',
                        'EndTime'    => $record['end_time'] ?? '',
                        'Secrecy'    => $record['secrecy'] ?? '0',
                        'Type'       => $record['type'] ?? 'all',
                        'RecorderID' => $record['recorder_id'] ?? '',
                    ],
                ];
            }

            $response = array_merge($response, $recordList);
        }

        return $this->generateXml('Response', $response);
    }
}