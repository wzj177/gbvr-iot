<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

/**
 * MediaStatus 媒体状态通知处理器 (GB28181-2022)
 * 
 * 用于处理设备上报的媒体状态信息（通过 NOTIFY）
 * 
 * 通知类型:
 * - SnapshotComplete: 图像抓拍完成
 * - Keepalive: 媒体流心跳
 */
class MediaStatusCommand extends BaseCommand
{
    public function getCommandType(): string
    {
        return 'MediaStatus';
    }

    /**
     * 处理媒体状态通知
     * 
     * @param SimpleXMLElement $xml XML数据
     * @param string $deviceId 设备ID
     * @param array $options 额外选项
     * @return array 处理结果
     */
    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): mixed
    {
        $notifyType = (string)($xml->NotifyType ?? '');
        
        // 通用字段
        $data = [
            'device_id' => $deviceId,
            'cmd_type' => $this->getCommandType(),
            'notify_type' => $notifyType,
            'session_id' => (string)($xml->SessionID ?? ''),
            'device_id_xml' => (string)($xml->DeviceID ?? ''),
        ];
        
        // 根据通知类型解析不同字段
        if ($notifyType === 'SnapshotComplete') {
            // 图像抓拍完成
            $data['file_url'] = (string)($xml->FileURL ?? '');
        } elseif ($notifyType === 'Keepalive') {
            // 媒体流心跳
            $data['ssrc'] = (string)($xml->SSRC ?? '');
            $data['bit_rate'] = (string)($xml->BitRate ?? '');
            $data['frame_rate'] = (string)($xml->FrameRate ?? '');
            $data['packet_loss'] = (string)($xml->PacketLoss ?? '');
        }
        
        return $data;
    }

    /**
     * 生成响应（MediaStatus 是单向通知，通常只需要 200 OK）
     * 
     * @param array $data 数据
     * @param int $sn 序列号
     * @return string XML字符串
     */
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
