<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

/**
 * MobilePosition 位置信息命令处理器
 *
 * 用于处理设备上报的位置信息（通过 MESSAGE 或 NOTIFY）
 */
class MobilePositionCommand extends BaseCommand
{
    public function getCommandType() : string
    {
        return 'MobilePosition';
    }

    /**
     * 处理位置信息
     *
     * @param SimpleXMLElement $xml XML数据
     * @param string $deviceId 设备ID
     * @param array $options 额外选项
     * @return array 处理结果
     */
    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []) : mixed
    {
        // 解析位置数据
        $time = (string)($xml->Time ?? '');
        $longitude = (float)($xml->Longitude ?? 0);
        $latitude = (float)($xml->Latitude ?? 0);
        $speed = (float)($xml->Speed ?? 0);
        $direction = (float)($xml->Direction ?? 0);
        $altitude = (float)($xml->Altitude ?? 0);

        return [
            'device_id' => $deviceId,
            'cmd_type'  => $this->getCommandType(),
            'time'      => $time,
            'longitude' => $longitude,
            'latitude'  => $latitude,
            'speed'     => $speed,
            'direction' => $direction,
            'altitude'  => $altitude,
        ];
    }

    /**
     * 生成位置查询响应（一般不需要，设备主动上报）
     *
     * @param array $data 数据
     * @param int $sn 序列号
     * @return string XML字符串
     */
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
