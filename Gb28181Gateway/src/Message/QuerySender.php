<?php

namespace Gb28181\GateWay\Message;

use Gb28181\GateWay\Device\Device;
use \SimpleXMLElement;
use Gb28181\GateWay\Libs\Logger;

/**
 * GB28181 查询发送器
 * 用于主动向设备发送查询和控制命令
 */
class QuerySender
{
    private $sipServer;
    private array $config;
    private int $sn = 1;
    private Logger $logger;

    /**
     * 每个 dialog 维护独立的 CSeq 序列
     * key = dialog_id, value = 当前 CSeq 值
     * @var array<int, int>
     */
    private array $dialogCSeqMap = [];

    public function __construct($sipServer, array $config = [])
    {
        $this->sipServer = $sipServer;
        $this->config = array_merge([
            'server_id' => '34020000002000000001',
            'server_domain' => '3402000000',
            'debug' => false,
        ], $config);
        $this->logger = Logger::getInstance();
    }

    /**
     * 获取指定 dialog 的下一个 CSeq 值
     *
     * @param int $dialogId Dialog ID
     * @return int 下一个 CSeq 值
     */
    private function getNextCSeq(int $dialogId): int
    {
        if (!isset($this->dialogCSeqMap[$dialogId])) {
            $this->dialogCSeqMap[$dialogId] = 0;
        }
        return ++$this->dialogCSeqMap[$dialogId];
    }

    /**
     * 清理指定 dialog 的 CSeq 记录（会话结束时调用）
     *
     * @param int $dialogId Dialog ID
     */
    public function clearDialogCSeq(int $dialogId): void
    {
        unset($this->dialogCSeqMap[$dialogId]);
    }

    /**
     * 生成序列号
     */
    private function generateSN(): int
    {
        $this->sn++;
        if ($this->sn > 99999999) {
            $this->sn = 1;
        }
        return $this->sn;
    }

    /**
     * 解析字符集
     * 
     * @param string|null $charset 设备配置的字符集 (gb2312/utf8/auto)
     * @return string 解析后的字符集 (GB2312 或 UTF-8)
     */
    private function resolveCharset(?string $charset): string
    {
        if ($charset === null || $charset === '' || strtolower($charset) === 'auto') {
            return 'GB2312';  // 默认 GB2312
        }
        // 规范化字符集名称
        $lower = strtolower($charset);
        if ($lower === 'utf8' || $lower === 'utf-8') {
            return 'UTF-8';
        }
        if ($lower === 'gb2312' || $lower === 'gbk') {
            return 'GB2312';
        }
        return 'GB2312';  // 未知字符集默认 GB2312
    }

    /**
     * 构建查询 XML
     * 
     * @param string $cmdType 命令类型
     * @param string $deviceId 设备ID
     * @param string $charset 字符集 (默认 GB2312)
     * @return string XML 字符串
     */
    private function buildQueryXml(string $cmdType, string $deviceId, string $charset = 'GB2312'): string
    {
        $sn = $this->generateSN();
        $encoding = $this->resolveCharset($charset);

        // 手动构建 XML，确保格式正确
        $xml = '<?xml version="1.0" encoding="' . $encoding . '"?>' . "\r\n";
        $xml .= '<Query>' . "\r\n";
        $xml .= '<CmdType>' . $cmdType . '</CmdType>' . "\r\n";
        $xml .= '<SN>' . $sn . '</SN>' . "\r\n";
        $xml .= '<DeviceID>' . $deviceId . '</DeviceID>' . "\r\n";
        $xml .= '</Query>';

        return $xml;
    }

    /**
     * 构建控制 XML
     * 
     * @param string $cmdType 命令类型
     * @param string $deviceId 设备ID
     * @param array $params 额外参数
     * @param string $charset 字符集 (默认 GB2312)
     * @return string XML 字符串
     */
    private function buildControlXml(string $cmdType, string $deviceId, array $params, string $charset = 'GB2312'): string
    {
        $sn = $this->generateSN();
        $encoding = $this->resolveCharset($charset);
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"{$encoding}\"?><Control></Control>");
        $xml->addChild('CmdType', $cmdType);
        $xml->addChild('SN', (string)$sn);
        $xml->addChild('DeviceID', $deviceId);

        // 添加额外参数
        foreach ($params as $key => $value) {
            $xml->addChild($key, (string)$value);
        }

        return $xml->asXML();
    }

    /**
     * 发送目录查询
     * 
     * @param string $deviceUri 设备 URI
     * @param string $deviceId 设备 ID
     * @param string $charset 字符集 (默认 GB2312, 可选: utf8/auto)
     */
    public function queryCatalog(string $deviceUri, string $deviceId, string $charset = 'GB2312'): bool|int
    {
        $xml = $this->buildQueryXml('Catalog', $deviceId, $charset);

        if ($this->config['debug']) {
            error_log("[DEBUG] QuerySender sending Catalog query to: {$deviceUri}");
            error_log("[DEBUG] XML Body:\n{$xml}");
        }

        $result = $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');

        if ($this->config['debug']) {
            error_log("[DEBUG] sendMessage result: " . ($result ? 'true' : 'false'));
        }

        return $result;
    }

    /**
     * 发送设备信息查询
     * 
     * @param string $deviceUri 设备 URI
     * @param string $deviceId 设备 ID
     * @param string $charset 字符集 (默认 GB2312, 可选: utf8/auto)
     */
    public function queryDeviceInfo(string $deviceUri, string $deviceId, string $charset = 'GB2312'): bool|int
    {
        $xml = $this->buildQueryXml('DeviceInfo', $deviceId, $charset);
        return $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');
    }

    /**
     * 发送设备状态查询
     * 
     * @param string $deviceUri 设备 URI
     * @param string $deviceId 设备 ID
     * @param string $charset 字符集 (默认 GB2312, 可选: utf8/auto)
     */
    public function queryDeviceStatus(string $deviceUri, string $deviceId, string $charset = 'GB2312'): bool|int
    {
        $xml = $this->buildQueryXml('DeviceStatus', $deviceId, $charset);
        return $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');
    }

    /**
     * 发送录像文件查询
     * 
     * @param string $deviceUri 设备 URI
     * @param string $deviceId 设备 ID
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @param string $type 类型
     * @param string $charset 字符集 (默认 GB2312, 可选: utf8/auto)
     */
    public function queryRecordInfo(string $deviceUri, string $deviceId, string $startTime, string $endTime, string $type = 'all', string $charset = 'GB2312'): bool|int
    {
        $sn = $this->generateSN();
        $encoding = $this->resolveCharset($charset);
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"{$encoding}\"?><Query></Query>");
        $xml->addChild('CmdType', 'RecordInfo');
        $xml->addChild('SN', (string)$sn);
        $xml->addChild('DeviceID', $deviceId);
        $xml->addChild('StartTime', $startTime);
        $xml->addChild('EndTime', $endTime);
        $xml->addChild('Type', $type);

        return $this->sipServer->sendMessage($deviceUri, $xml->asXML(), 'Application/MANSCDP+xml');
    }

    /**
     * PTZ 控制
     * 
     * @param string $deviceUri 设备 URI
     * @param string $channelId 通道 ID
     * @param string $ptzCmd PTZ 控制命令
     * @param string $charset 字符集 (默认 GB2312, 可选: utf8/auto)
     */
    public function ptzControl(string $deviceUri, string $channelId, string $ptzCmd, string $charset = 'GB2312'): bool|int
    {
        $xml = $this->buildControlXml('DeviceControl', $channelId, [
            'PTZCmd' => $ptzCmd
        ], $charset);
        return $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');
    }


    /**
     * 发送目录订阅
     * 
     * @param Device $device 设备对象
     * @param int $expires 订阅有效期（秒）
     * @return int 订阅ID（dialog_id），用于续订和取消订阅
     */
    public function sendSubscribeCatalog(Device $device, int $expires = 3600): int
    {
        try {
            $eventType = 'Catalog';
            $deviceId = $device->deviceId;
            $encoding = $this->resolveCharset($device->charset);

            // 构造目标 SIP URI
            $toUri = "sip:{$deviceId}@{$device->ip}:{$device->port}";

            // 构造 GB28181 订阅 XML 消息体
//            $sn = time();
            $sn = (int) ((mt_rand() / mt_getrandmax() * 9 + 1) * 100000);
            $xmlBody = "<?xml version=\"1.0\" encoding=\"{$encoding}\"?>\r\n";
            $xmlBody .= "<Query>\r\n";
            $xmlBody .= "<CmdType>Catalog</CmdType>\r\n";
            $xmlBody .= "<SN>{$sn}</SN>\r\n";
            $xmlBody .= "<DeviceID>{$deviceId}</DeviceID>\r\n";
            $xmlBody .= "</Query>\r\n";

            // 使用正确的 API: subscribe(toUri, eventType, expires, xmlBody)
            $subscriptionId = $this->sipServer->subscribe($toUri, $eventType, $expires, $xmlBody);

            if ($subscriptionId === false) {
                throw new \RuntimeException('订阅请求发送失败');
            }

            // 记录订阅信息到设备（包含 subscription_id）
            $device->addSubscription($eventType, $subscriptionId, $expires);

            $this->logger->info('发送目录订阅成功', 'send_subscribe_catalog');

            return $subscriptionId;
        } catch (\Throwable $e) {
            $this->logger->error('发送目录订阅失败,' . $e->getMessage(), 'send_subscribe_catalog');
            throw $e;
        }
    }

    /**
     * 发送报警订阅
     * 
     * @param Device $device 设备对象
     * @param int $expires 订阅有效期（秒）
     * @param int|null $startAlarmPriority 起始报警优先级 (1~4, 1最低,4最高)
     * @param int|null $endAlarmPriority 终止报警优先级 (1~4)
     * @param string|null $alarmMethod 报警方式 (1=电话,2=设备故障,3=视频丢失,4=移动侦测...)
     * @param string|null $alarmType 报警类型 (1=视频遮挡,2=视频丢失,3=移动侦测,4=声光报警...)
     * @param string|null $startAlarmTime 起始时间 (ISO8601格式, 如: 2025-01-01T00:00:00)
     * @param string|null $endAlarmTime 终止时间 (ISO8601格式, 如: 2025-12-31T23:59:59)
     * @return int 订阅ID（subscription_id），用于续订和取消订阅
     */
    public function sendSubscribeAlarm(
        Device  $device,
        int     $expires = 3600,
        ?int     $startAlarmPriority = null,
        ?int     $endAlarmPriority = null,
        ?string $alarmMethod = null,
        ?string $alarmType = null,
        ?string $startAlarmTime = null,
        ?string $endAlarmTime = null
    ): int
    {
        try {
            // GB28181 标准：报警订阅使用 Event: presence，通过 CmdType 区分
            $eventType = 'presence';
            $subscriptionType = 'Alarm';  // 用于设备记录
            $deviceId = $device->deviceId;
            $encoding = $this->resolveCharset($device->charset);

            // 构造目标 SIP URI
            $toUri = "sip:{$deviceId}@{$device->ip}:{$device->port}";

            // 构造 GB28181 报警订阅 XML 消息体
//            $sn = time();
            $sn = (int) ((mt_rand() / mt_getrandmax() * 9 + 1) * 100000);
            $xmlBody = "<?xml version=\"1.0\" encoding=\"{$encoding}\"?>\r\n";
            $xmlBody .= "<Query>\r\n";
            $xmlBody .= "<CmdType>Alarm</CmdType>\r\n";
            $xmlBody .= "<SN>{$sn}</SN>\r\n";
            $xmlBody .= "<DeviceID>{$deviceId}</DeviceID>\r\n";
            // 可选字段：报警优先级范围
            $startAlarmPriority && $xmlBody .= "<StartAlarmPriority>{$startAlarmPriority}</StartAlarmPriority>\r\n";
            $endAlarmPriority && $xmlBody .= "<EndAlarmPriority>{$endAlarmPriority}</EndAlarmPriority>\r\n";
            // 可选字段：报警方式
            if ($alarmMethod !== null) {
                $xmlBody .= "<AlarmMethod>{$alarmMethod}</AlarmMethod>\r\n";
            }
            // 可选字段：报警类型
            if ($alarmType !== null) {
                $xmlBody .= "<AlarmType>{$alarmType}</AlarmType>\r\n";
            }
            // 可选字段：报警时间范围 (ISO8601格式)
            if ($startAlarmTime !== null) {
                $xmlBody .= "<StartAlarmTime>{$startAlarmTime}</StartAlarmTime>\r\n";
            }
            if ($endAlarmTime !== null) {
                $xmlBody .= "<EndAlarmTime>{$endAlarmTime}</EndAlarmTime>\r\n";
            }
            $xmlBody .= "</Query>\r\n";

            // 使用正确的 API: subscribe(toUri, eventType, expires, xmlBody)
            $subscriptionId = $this->sipServer->subscribe($toUri, $eventType, $expires, $xmlBody);

            if ($subscriptionId === false) {
                throw new \RuntimeException('订阅请求发送失败');
            }

            // 记录订阅信息到设备（包含 subscription_id）
            // 注意：使用 subscriptionType ('Alarm') 而不是 eventType ('presence')
            $device->addSubscription($subscriptionType, $subscriptionId, $expires, [
                'start_priority' => $startAlarmPriority,
                'end_priority' => $endAlarmPriority,
                'alarm_method' => $alarmMethod,
                'start_alarm_time' => $startAlarmTime,
                'end_alarm_time' => $endAlarmTime,
                'sip_event_type' => $eventType  // 记录实际的 SIP Event 头值
            ]);

            $this->logger->info('发送报警订阅成功');

            return $subscriptionId;
        } catch (\Throwable $e) {
            $this->logger->error('发送报警订阅失败,' . $e->getMessage(), 'send_subscribe_alarm');

            throw $e;
        }
    }

    /**
     * 发送移动位置订阅
     * 
     * @param Device $device 设备对象
     * @param int $expires 订阅有效期（秒）
     * @param int $interval 位置上报间隔（秒）
     * @return int 订阅ID（subscription_id），用于续订和取消订阅
     */
    public function sendSubscribeMobilePosition(Device $device, int $expires = 3600, int $interval = 5): int
    {
        try {
            // GB28181 标准：移动位置订阅使用 Event: presence，通过 CmdType 区分
            $eventType = 'presence';
            $subscriptionType = 'MobilePosition';  // 用于设备记录
            $deviceId = $device->deviceId;
            $encoding = $this->resolveCharset($device->charset);

            // 构造目标 SIP URI
            $toUri = "sip:{$deviceId}@{$device->ip}:{$device->port}";

            // 构造 GB28181 移动位置订阅 XML 消息体
//            $sn = time();
            $sn = (int) ((mt_rand() / mt_getrandmax() * 9 + 1) * 100000);
            $xmlBody = "<?xml version=\"1.0\" encoding=\"{$encoding}\"?>\r\n";
            $xmlBody .= "<Query>\r\n";
            $xmlBody .= "<CmdType>MobilePosition</CmdType>\r\n";
            $xmlBody .= "<SN>{$sn}</SN>\r\n";
            $xmlBody .= "<DeviceID>{$deviceId}</DeviceID>\r\n";
            $xmlBody .= "<Interval>{$interval}</Interval>\r\n";
            $xmlBody .= "</Query>\r\n";

            // 使用正确的 API: subscribe(toUri, eventType, expires, xmlBody)
            $subscriptionId = $this->sipServer->subscribe($toUri, $eventType, $expires, $xmlBody);

            if ($subscriptionId === false) {
                throw new \RuntimeException('订阅请求发送失败');
            }

            // 记录订阅信息到设备（包含 subscription_id）
            // 注意：使用 subscriptionType ('MobilePosition') 而不是 eventType ('presence')
            $device->addSubscription($subscriptionType, $subscriptionId, $expires, [
                'interval' => $interval,
                'sip_event_type' => $eventType  // 记录实际的 SIP Event 头值
            ]);

            $this->logger->info('发送移动位置订阅成功');

            return $subscriptionId;
        } catch (\Throwable $e) {
            $this->logger->error('发送移动位置订阅失败,' . $e->getMessage(), 'send_mobile_position_subscribe');

            throw $e;
        }
    }

//PTZ 精准状态查询或订阅请求
//<! - 命令类型：PTZ 精准状态查询(必选)一>
//<elementname="CmdType"fixed="PTZPosition"/>
//<!-命令序列号(必选)--〉
//<element               name="SN"type="tg:SNType"/>
//<!-查询目标设备编码(必选)--〉
//<elementname="DeviceID"type="tg:deviceIDType"/>
//GB/T  28181—2022
//
//A.2.4.14  存储卡状态查询请求
//〈! --  命令类型：存储卡状态查询(可选)--〉
//<element                 name="CmdType"fixed="SDCardStatus"minOccurs="0"/>
//<! - 命令序列号(必选) -〉
//<element           name="SN"type="tg:SNType"/>
//<!-查询目标设备编码(必选)--)
//<element           name="DevicelD"type="tg:devicelDType"/>\

public function sendSubscribePtzStatus(Device $device): void
{

}

    /**
     * 取消目录订阅
     */
    public function sendUnsubscribeCatalog(Device $device): void
    {
        try {
            $eventType = 'Catalog';
            $deviceId = $device->deviceId;

            // 获取订阅 ID
            $subscriptionId = $device->getSubscriptionId($eventType);
            if ($subscriptionId === null) {
                $this->logger->warning('取消订阅失败：未找到订阅记录', [
                    'device_id' => $deviceId,
                    'event_type' => $eventType
                ]);
                return;
            }

            // 使用正确的 API: cancelSubscribe(subscriptionId)
            $result = $this->sipServer->cancelSubscribe($subscriptionId);

            // 从设备移除订阅信息
            $device->removeSubscription($eventType);

            $this->logger->info('取消目录订阅成功');
        } catch (\Throwable $e) {
            $this->logger->error('取消目录订阅失败：' . $e->getMessage(), 'cancel_catalog_subscribe');
            throw $e;
        }
    }

    /**
     * 取消报警订阅
     */
    public function sendUnsubscribeAlarm(Device $device): void
    {
        try {
            $eventType = 'Alarm';
            $deviceId = $device->deviceId;

            // 获取订阅 ID
            $subscriptionId = $device->getSubscriptionId($eventType);
            if ($subscriptionId === null) {
                $this->logger->warning('取消订阅失败：未找到订阅记录', [
                    'device_id' => $deviceId,
                    'event_type' => $eventType
                ]);
                return;
            }

            // 使用正确的 API: cancelSubscribe(subscriptionId)
            $result = $this->sipServer->cancelSubscribe($subscriptionId);

            // 从设备移除订阅信息
            $device->removeSubscription($eventType);

            $this->logger->info('取消报警订阅成功', 'cancel_alarm');
        } catch (\Throwable $e) {
            $this->logger->error('取消报警订阅失败：' . $e->getMessage(), 'cancel_alarm');
            throw $e;
        }
    }

    /**
     * 取消移动位置订阅
     */
    public function sendUnsubscribeMobilePosition(Device $device): void
    {
        try {
            $eventType = 'MobilePosition';
            $deviceId = $device->deviceId;

            // 获取订阅 ID
            $subscriptionId = $device->getSubscriptionId($eventType);
            if ($subscriptionId === null) {
                $this->logger->warning('取消订阅失败：未找到订阅记录');
                return;
            }

            // 使用正确的 API: cancelSubscribe(subscriptionId)
            $result = $this->sipServer->cancelSubscribe($subscriptionId);

            // 从设备移除订阅信息
            $device->removeSubscription($eventType);

            $this->logger->info('取消移动位置订阅成功');
        } catch (\Throwable $e) {
            $this->logger->error('取消移动位置订阅失败：' . $e->getMessage(), 'cancel_mobile_position');
            throw $e;
        }
    }

    /**
     * 录像回放控制（倍速、暂停、拖动等）
     *
     * 重要: GB28181 标准要求回放控制使用 SIP INFO 方法（不是 MESSAGE）
     * INFO 必须在已建立的 INVITE 会话内发送，使用 MANSRTSP 协议格式
     *
     * @param int $dialogId INVITE 会话的 Dialog ID (由 sendInvite 建立)
     * @param string $action 控制动作: play, pause, seek, scale, teardown
     * @param array $options 额外参数:
     *   - range: string 播放范围 (如 "npt=30-" 表示从30秒开始)
     *   - scale: float 播放速度 (0.5, 1.0, 2.0, 4.0 等)
     * @return bool 成功返回 true, 失败返回 false
     *
     * @example
     * // 暂停回放
     * $querySender->playbackControl($dialogId, 'pause');
     *
     * // 恢复播放
     * $querySender->playbackControl($dialogId, 'play');
     *
     * // 拖动到30秒位置
     * $querySender->playbackControl($dialogId, 'seek', ['range' => 'npt=30-']);
     *
     * // 2倍速播放
     * $querySender->playbackControl($dialogId, 'scale', ['scale' => 2.0]);
     *
     * // 停止回放
     * $querySender->playbackControl($dialogId, 'teardown');
     */
    public function playbackControl(
        int    $dialogId,
        string $action,
        array  $options = []
    ): bool
    {
        // 获取该 dialog 的下一个 CSeq 值（每个会话维护独立的序列）
        $cseq = $this->getNextCSeq($dialogId);

        // 构建 MANSRTSP 消息体
        $body = $this->buildMansrtspBody($action, $cseq, $options);

        if ($this->config['debug']) {
            $this->logger->debug("PlaybackControl: " . json_encode([
                    'dialog_id' => $dialogId,
                    'action' => $action,
                    'cseq' => $cseq,
                    'body' => $body
                ]));
        }

        // 使用 SIP INFO 发送（关键修复：不再使用 MESSAGE）
        return $this->sipServer->sendInfo($dialogId, $body, 'Application/MANSRTSP');
    }

    /**
     * 构建 MANSRTSP 协议消息体（GB/T 28181-2022 附录 B）
     *
     * 标准支持的命令：
     * - PLAY: 播放/恢复/跳转（B.2.1, B.2.3, B.2.4, B.2.8）
     * - PAUSE: 暂停（B.2.2）
     * - TEARDOWN: 停止（B.2.5）
     *
     * @param string $action 动作类型
     * @param int $cseq 序列号（每个 dialog 独立维护）
     * @param array $options 额外参数
     * @return string MANSRTSP 格式的消息体
     */
    private function buildMansrtspBody(string $action, int $cseq, array $options): string
    {
        $lines = [];

        switch (strtolower($action)) {
            case 'pause':
                // 暂停播放（GB28181 B.2.2）
                // 只携带 PauseTime: now，不携带其他头
                $lines[] = "PAUSE RTSP/1.0";
                $lines[] = "CSeq: {$cseq}";
                $lines[] = "PauseTime: now";
                break;

            case 'play':
            case 'resume':
                // 恢复播放（GB28181 B.2.1）
                // Range: npt=now-，不携带 Scale 头，表示从暂停位置以原倍速恢复
                $lines[] = "PLAY RTSP/1.0";
                $lines[] = "CSeq: {$cseq}";
                $range = $options['range'] ?? 'npt=now-';
                $lines[] = "Range: {$range}";
                
                // 如果同时指定了 scale，也添加（支持恢复并切换倍速）
                if (isset($options['scale'])) {
                    $lines[] = "Scale: {$options['scale']}";
                }
                break;

            case 'seek':
                // 随机拖放（GB28181 B.2.4）
                // PLAY + Range: npt=100-，不携带 Scale 头，表示跳转到指定位置
                $lines[] = "PLAY RTSP/1.0";
                $lines[] = "CSeq: {$cseq}";
                $range = $options['range'] ?? 'npt=0-';
                $lines[] = "Range: {$range}";
                break;

            case 'scale':
            case 'speed':
                // 倍速播放（GB28181 B.2.3）
                // PLAY + Scale: N，不携带 Range 头，表示从当前位置以指定倍速播放
                // 标准支持：0.25, 0.5, 1, 2, 4
                $lines[] = "PLAY RTSP/1.0";
                $lines[] = "CSeq: {$cseq}";
                $scale = $options['scale'] ?? 1.00000;
                $lines[] = "Scale: {$scale}";
//                if (isset($options['range'])) {
//                    $lines[] = "Range: {$options['range']}";
//                }
                break;

            case 'reverse':
                // 倒放（GB28181 B.2.8）
                // PLAY + Scale: -1（负数表示倒放）
                // 标准：至少支持 -1（一倍速倒放）
                $lines[] = "PLAY RTSP/1.0";
                $lines[] = "CSeq: {$cseq}";
                $scale = $options['scale'] ?? -1.000000;
                $lines[] = "Scale: {$scale}";
                
                // 可选：指定倒放起点
                // Range: npt=600-120 表示从第600秒倒放到第120秒
                if (isset($options['range'])) {
                    $lines[] = "Range: {$options['range']}";
                }
                break;

            case 'teardown':
            case 'stop':
                // 停止播放（GB28181 B.2.5）
                // 结束会话并释放资源
                $lines[] = "TEARDOWN RTSP/1.0";
                $lines[] = "CSeq: {$cseq}";
                break;

            default:
                throw new \InvalidArgumentException("Unknown playback action: {$action}");
        }

        // MANSRTSP 使用 CRLF 作为行结束符（标准要求）
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * 快捷方法：暂停回放
     *
     * @param int $dialogId INVITE 会话的 Dialog ID
     * @return bool
     */
    public function playbackPause(int $dialogId): bool
    {
        return $this->playbackControl($dialogId, 'pause');
    }

    /**
     * 快捷方法：恢复播放
     *
     * @param int $dialogId INVITE 会话的 Dialog ID
     * @return bool
     */
    public function playbackResume(int $dialogId): bool
    {
        return $this->playbackControl($dialogId, 'play');
    }

    /**
     * 快捷方法：拖动到指定位置
     *
     * @param int $dialogId INVITE 会话的 Dialog ID
     * @param float $seconds 秒数（从录像开始算起）
     * @return bool
     */
    public function playbackSeek(int $dialogId, float $seconds): bool
    {
        return $this->playbackControl($dialogId, 'seek', [
            'range' => "npt={$seconds}-"
        ]);
    }

    /**
     * 快捷方法：设置播放速度
     *
     * @param int $dialogId INVITE 会话的 Dialog ID
     * @param float $scale 速度倍数 (0.5=半速, 1.0=正常, 2.0=2倍速, 4.0=4倍速)
     * @return bool
     */
    public function playbackSpeed(int $dialogId, float $scale): bool
    {
        return $this->playbackControl($dialogId, 'scale', [
            'scale' => $scale
        ]);
    }

    /**
     * 快捷方法：停止回放
     *
     * @param int $dialogId INVITE 会话的 Dialog ID
     * @return bool
     */
    public function playbackStop(int $dialogId): bool
    {
        return $this->playbackControl($dialogId, 'teardown');
    }

    /**
     * 快捷方法：倒放（GB28181-2022 B.2.8）
     *
     * @param int $dialogId INVITE 会话的 Dialog ID
     * @param float $speed 倒放倍速（默认1，表示一倍速倒放）
     * @param float|null $startSeconds 倒放起点（秒，可选。如600表示从第600秒开始倒放）
     * @param float|null $endSeconds 倒放终点（秒，可选。如120表示倒放到第120秒停止）
     * @return bool
     */
    public function playbackReverse(int $dialogId, float $speed = 1.0, ?float $startSeconds = null, ?float $endSeconds = null): bool
    {
        $options = [
            'scale' => -abs($speed)  // 负数表示倒放
        ];
        
        // 如果指定了倒放范围
        if ($startSeconds !== null) {
            if ($endSeconds !== null) {
                // Range: npt=600-120 表示从第600秒倒放到第120秒
                $options['range'] = "npt={$startSeconds}-{$endSeconds}";
            } else {
                // Range: npt=600- 表示从第600秒倒放到开始
                $options['range'] = "npt={$startSeconds}-";
            }
        }
        // 否则从当前位置倒放（相当于 Range: npt=now-）
        
        return $this->playbackControl($dialogId, 'reverse', $options);
    }

    /**
     * 快捷方法：跳转并指定倍速播放
     *
     * @param int $dialogId INVITE 会话的 Dialog ID
     * @param float $seconds 跳转位置（秒）
     * @param float $scale 播放倍速（默认1.0）
     * @return bool
     */
    public function playbackSeekAndPlay(int $dialogId, float $seconds, float $scale = 1.0): bool
    {
        $options = [
            'range' => "npt={$seconds}-"
        ];
        
        // 如果倍速不是1.0，添加 Scale 头
        if (abs($scale - 1.0) > 0.01) {
            $options['scale'] = $scale;
        }
        
        return $this->playbackControl($dialogId, 'play', $options);
    }
}
