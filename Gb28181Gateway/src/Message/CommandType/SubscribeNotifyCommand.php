<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

/**
 * 订阅通知命令处理器
 *
 * 统一处理 GB28181 SUBSCRIBE/NOTIFY 机制中的通知消息
 *
 * 支持的事件类型（通过 Event 头域判断）：
 * - Catalog: 目录变更通知（设备/通道增删改）
 * - Alarm: 报警事件订阅通知
 * - MobilePosition: 移动设备位置订阅通知
 * - presence: 兼容旧版位置订阅（GB28181-2016）
 *
 * 与普通 MESSAGE 命令的区别：
 * - NOTIFY 通过 Event 头分发，MESSAGE 通过 CmdType 分发
 * - NOTIFY 支持 Subscription-State 头判断订阅状态
 * - NOTIFY 是异步推送，MESSAGE 是请求-响应模式
 */
class SubscribeNotifyCommand extends BaseCommand
{
    /**
     * 报警方法映射
     */
    private const ALARM_METHOD_MAP = [
        '1' => '电话报警',
        '2' => '设备报警',
        '3' => '短信报警',
        '4' => 'GPS报警',
        '5' => '视频报警',
        '6' => '设备故障报警',
        '7' => '其他报警',
    ];

    /**
     * 报警优先级映射
     */
    private const ALARM_PRIORITY_MAP = [
        '1' => '一级警情(最高)',
        '2' => '二级警情',
        '3' => '三级警情',
        '4' => '四级警情(最低)',
    ];

    /**
     * 目录变更事件类型
     */
    private const CATALOG_EVENT_TYPES = [
        'ON' => '设备上线',
        'OFF' => '设备下线',
        'VLOST' => '视频丢失',
        'DEFECT' => '故障',
        'ADD' => '增加',
        'DEL' => '删除',
        'UPDATE' => '更新',
    ];

    /**
     * 获取命令类型
     * 注意：NOTIFY 不是通过 CmdType 分发，而是通过 Event 头
     * 这里返回特殊值用于标识
     */
    public function getCommandType(): string
    {
        return 'SubscribeNotify';
    }

    /**
     * 处理订阅通知
     *
     * @param SimpleXMLElement $xml 解析后的 XML
     * @param string $deviceId 设备ID
     * @param array $options 选项，包含：
     *   - event_type: Event 头的值 (catalog, alarm, mobileposition, presence)
     *   - subscription_state: Subscription-State 头的值
     *   - sip_event: \SipEvent 对象（可选）
     * @return array 处理结果
     */
    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): mixed
    {
        $eventType = strtolower($options['event_type'] ?? '');
        $subscriptionState = $options['subscription_state'] ?? '';
        
        // 检查订阅是否终止
        $isTerminated = stripos($subscriptionState, 'terminated') !== false;
        
        // 基础结果
        $result = [
            'device_id' => $deviceId,
            'cmd_type' => 'SubscribeNotify',
            'event_type' => $eventType,
            'subscription_state' => $subscriptionState,
            'is_terminated' => $isTerminated,
            'sn' => (string)($xml->SN ?? ''),
            'notify_device_id' => (string)($xml->DeviceID ?? $deviceId),
            'timestamp' => time(),
        ];
        
        // 根据事件类型分发处理
        switch ($eventType) {
            case 'catalog':
                return $this->handleCatalog($xml, $deviceId, $result);
                
            case 'alarm':
                return $this->handleAlarm($xml, $deviceId, $result);
                
            case 'mobileposition':
            case 'presence':
                return $this->handleMobilePosition($xml, $deviceId, $result);
                
            default:
                // 未知事件类型，返回基础信息
                $result['raw_xml'] = $xml->asXML();
                return $result;
        }
    }

    /**
     * 处理目录变更通知
     *
     * @param SimpleXMLElement $xml XML 消息体
     * @param string $deviceId 设备ID
     * @param array $result 基础结果
     * @return array 处理结果
     */
    private function handleCatalog(SimpleXMLElement $xml, string $deviceId, array $result): array
    {
        $result['notify_type'] = 'catalog';
        $result['sum_num'] = (int)($xml->SumNum ?? 0);
        $result['event'] = (string)($xml->Event ?? '');
        $result['event_desc'] = self::CATALOG_EVENT_TYPES[$result['event']] ?? $result['event'];
        
        // 解析设备列表
        $deviceList = [];
        if (isset($xml->DeviceList) && isset($xml->DeviceList->Item)) {
            foreach ($xml->DeviceList->Item as $item) {
                $channelInfo = [
                    'device_id' => (string)($item->DeviceID ?? ''),
                    'name' => (string)($item->Name ?? ''),
                    'manufacturer' => (string)($item->Manufacturer ?? ''),
                    'model' => (string)($item->Model ?? ''),
                    'owner' => (string)($item->Owner ?? ''),
                    'civil_code' => (string)($item->CivilCode ?? ''),
                    'address' => (string)($item->Address ?? ''),
                    'parental' => (string)($item->Parental ?? ''),
                    'parent_id' => (string)($item->ParentID ?? ''),
                    'safety_way' => (string)($item->SafetyWay ?? ''),
                    'register_way' => (string)($item->RegisterWay ?? ''),
                    'secrecy' => (string)($item->Secrecy ?? ''),
                    'status' => (string)($item->Status ?? ''),
                    'longitude' => (string)($item->Longitude ?? ''),
                    'latitude' => (string)($item->Latitude ?? ''),
                    'ptz_type' => (string)($item->PTZType ?? ''),
                    // 目录变更事件类型（可能在 Item 中）
                    'event' => (string)($item->Event ?? $result['event']),
                ];
                $deviceList[] = $channelInfo;
            }
        }
        
        $result['device_list'] = $deviceList;
        $result['device_count'] = count($deviceList);
        
        return $result;
    }

    /**
     * 处理报警事件通知
     *
     * @param SimpleXMLElement $xml XML 消息体
     * @param string $deviceId 设备ID
     * @param array $result 基础结果
     * @return array 处理结果
     */
    private function handleAlarm(SimpleXMLElement $xml, string $deviceId, array $result): array
    {
        $result['notify_type'] = 'alarm';
        
        // 报警基本信息
        $alarmPriority = (string)($xml->AlarmPriority ?? '');
        $alarmMethod = (string)($xml->AlarmMethod ?? '');
        
        $result['alarm_device_id'] = (string)($xml->DeviceID ?? $deviceId);
        $result['alarm_priority'] = $alarmPriority;
        $result['alarm_priority_desc'] = self::ALARM_PRIORITY_MAP[$alarmPriority] ?? "未知({$alarmPriority})";
        $result['alarm_method'] = $alarmMethod;
        $result['alarm_method_desc'] = self::ALARM_METHOD_MAP[$alarmMethod] ?? "未知({$alarmMethod})";
        $result['alarm_type'] = (string)($xml->AlarmType ?? '');
        $result['alarm_time'] = (string)($xml->AlarmTime ?? '');
        $result['alarm_description'] = (string)($xml->AlarmDescription ?? '');
        
        // 位置信息（部分设备会提供）
        $result['longitude'] = (string)($xml->Longitude ?? '');
        $result['latitude'] = (string)($xml->Latitude ?? '');
        
        // GB28181-2022 扩展字段
        $result['alarm_level'] = (string)($xml->AlarmLevel ?? '');
        
        // 报警扩展信息（如果有）
        if (isset($xml->Info)) {
            $result['alarm_info'] = [];
            foreach ($xml->Info as $info) {
                $result['alarm_info'][] = [
                    'alarm_type' => (string)($info->AlarmType ?? ''),
                    'alarm_type_param' => (string)($info->AlarmTypeParam ?? ''),
                    'event_type' => (string)($info->EventType ?? ''),
                ];
            }
        }
        
        return $result;
    }

    /**
     * 处理移动设备位置通知
     *
     * @param SimpleXMLElement $xml XML 消息体
     * @param string $deviceId 设备ID
     * @param array $result 基础结果
     * @return array 处理结果
     */
    private function handleMobilePosition(SimpleXMLElement $xml, string $deviceId, array $result): array
    {
        $result['notify_type'] = 'mobile_position';
        
        // 位置信息
        $result['position_device_id'] = (string)($xml->DeviceID ?? $deviceId);
        $result['time'] = (string)($xml->Time ?? '');
        $result['longitude'] = (string)($xml->Longitude ?? '');
        $result['latitude'] = (string)($xml->Latitude ?? '');
        $result['speed'] = (string)($xml->Speed ?? '');
        $result['direction'] = (string)($xml->Direction ?? '');
        $result['altitude'] = (string)($xml->Altitude ?? '');
        
        return $result;
    }

    /**
     * 生成响应（NOTIFY 通常只需要 200 OK，不需要 XML 响应体）
     *
     * @param array $data 响应数据
     * @param int $sn 序列号
     * @return string XML 响应
     */
    public function generateResponse(array $data, int $sn): string
    {
        // NOTIFY 响应通常是空的 200 OK
        // 如果需要 XML 响应体（较少见），可以生成
        return $this->generateXml('Response', [
            'CmdType' => 'SubscribeNotify',
            'SN' => $sn,
            'DeviceID' => $data['device_id'] ?? '',
            'Result' => 'OK'
        ]);
    }

    /**
     * 检查是否为支持的事件类型
     *
     * @param string $eventType Event 头的值
     * @return bool 是否支持
     */
    public static function isSupportedEvent(string $eventType): bool
    {
        $supported = ['catalog', 'alarm', 'mobileposition', 'mobile_position', 'presence'];
        return in_array(strtolower($eventType), $supported);
    }

    /**
     * 获取事件类型的中文描述
     *
     * @param string $eventType Event 头的值
     * @return string 中文描述
     */
    public static function getEventTypeDesc(string $eventType): string
    {
        $map = [
            'catalog' => '目录变更',
            'alarm' => '报警事件',
            'mobileposition' => '移动设备位置',
            'presence' => '移动设备位置(旧版)',
        ];
        return $map[strtolower($eventType)] ?? $eventType;
    }
}
