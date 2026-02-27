<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

/**
 * 预置位查询响应处理
 *
 * 解析设备返回的预置位列表 XML:
 * <Response>
 *   <CmdType>PresetQuery</CmdType>
 *   <SN>...</SN>
 *   <DeviceID>...</DeviceID>
 *   <PresetList Num="...">
 *     <Item>
 *       <PresetID>1</PresetID>
 *       <PresetName>预置位1</PresetName>
 *     </Item>
 *     ...
 *   </PresetList>
 * </Response>
 */
class PresetQueryCommand extends BaseCommand
{
    public function getCommandType(): string
    {
        return 'PresetQuery';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): mixed
    {
        $presets = [];

        if (isset($xml->PresetList)) {
            foreach ($xml->PresetList->children() as $item) {
                $presetId = (string) ($item->PresetID ?? '');
                $presetName = (string) ($item->PresetName ?? '');
                if ($presetId !== '') {
                    $presets[] = [
                        'preset_id' => (int) $presetId,
                        'preset_name' => $presetName,
                    ];
                }
            }
        }

        return [
            'device_id' => $deviceId,
            'cmd_type' => $this->getCommandType(),
            'preset_list' => $presets,
            'num' => count($presets),
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
