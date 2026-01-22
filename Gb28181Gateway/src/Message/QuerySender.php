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
     * 构建查询 XML
     */
    private function buildQueryXml(string $cmdType, string $deviceId): string
    {
        $sn = $this->generateSN();

        // 手动构建 XML，确保格式正确
        $xml = '<?xml version="1.0" encoding="GB2312"?>' . "\r\n";
        $xml .= '<Query>' . "\r\n";
        $xml .= '<CmdType>' . $cmdType . '</CmdType>' . "\r\n";
        $xml .= '<SN>' . $sn . '</SN>' . "\r\n";
        $xml .= '<DeviceID>' . $deviceId . '</DeviceID>' . "\r\n";
        $xml .= '</Query>';

        return $xml;
    }

    /**
     * 构建控制 XML
     */
    private function buildControlXml(string $cmdType, string $deviceId, array $params): string
    {
        $sn = $this->generateSN();
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"GB2312\"?><Control></Control>");
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
     */
    public function queryCatalog(string $deviceUri, string $deviceId): bool|int
    {
        $xml = $this->buildQueryXml('Catalog', $deviceId);

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
     */
    public function queryDeviceInfo(string $deviceUri, string $deviceId): bool|int
    {
        $xml = $this->buildQueryXml('DeviceInfo', $deviceId);
        return $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');
    }

    /**
     * 发送设备状态查询
     */
    public function queryDeviceStatus(string $deviceUri, string $deviceId): bool|int
    {
        $xml = $this->buildQueryXml('DeviceStatus', $deviceId);
        return $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');
    }

    /**
     * 发送录像文件查询
     */
    public function queryRecordInfo(string $deviceUri, string $deviceId, string $startTime, string $endTime, string $type = 'all'): bool|int
    {
        $sn = $this->generateSN();
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"GB2312\"?><Query></Query>");
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
     */
    public function ptzControl(string $deviceUri, string $channelId, string $ptzCmd): bool|int
    {
        $xml = $this->buildControlXml('DeviceControl', $channelId, [
            'PTZCmd' => $ptzCmd
        ]);
        return $this->sipServer->sendMessage($deviceUri, $xml, 'Application/MANSCDP+xml');
    }


    /**
     * 发送目录订阅
     */
    public function sendSubscribeCatalog(Device $device, int $expires = 3600): void
    {
        try {
            $eventType = 'Catalog';
            $deviceId = $device->deviceId;

            // 构造目标 SIP URI
            $toUri = "sip:{$deviceId}@{$device->ip}:{$device->port}";

            // 构造 GB28181 订阅 XML 消息体
            $sn = time();
            $xmlBody = "<?xml version=\"1.0\" encoding=\"GB2312\"?>\r\n";
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

            $this->logger->info('发送目录订阅成功', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
                'expires' => $expires,
                'subscription_id' => $subscriptionId
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('发送目录订阅失败', [
                'device_id' => $device->deviceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 发送报警订阅
     */
    public function sendSubscribeAlarm(
        Device  $device,
        int     $expires = 3600,
        int     $startAlarmPriority = 0,
        int     $endAlarmPriority = 3,
        ?string $alarmMethod = null
    ): void
    {
        try {
            $eventType = 'Alarm';
            $deviceId = $device->deviceId;

            // 构造目标 SIP URI
            $toUri = "sip:{$deviceId}@{$device->ip}:{$device->port}";

            // 构造 GB28181 报警订阅 XML 消息体
            $sn = time();
            $xmlBody = "<?xml version=\"1.0\" encoding=\"GB2312\"?>\r\n";
            $xmlBody .= "<Query>\r\n";
            $xmlBody .= "<CmdType>Alarm</CmdType>\r\n";
            $xmlBody .= "<SN>{$sn}</SN>\r\n";
            $xmlBody .= "<DeviceID>{$deviceId}</DeviceID>\r\n";
            $xmlBody .= "<StartAlarmPriority>{$startAlarmPriority}</StartAlarmPriority>\r\n";
            $xmlBody .= "<EndAlarmPriority>{$endAlarmPriority}</EndAlarmPriority>\r\n";
            if ($alarmMethod !== null) {
                $xmlBody .= "<AlarmMethod>{$alarmMethod}</AlarmMethod>\r\n";
            }
            $xmlBody .= "</Query>\r\n";

            // 使用正确的 API: subscribe(toUri, eventType, expires, xmlBody)
            $subscriptionId = $this->sipServer->subscribe($toUri, $eventType, $expires, $xmlBody);

            if ($subscriptionId === false) {
                throw new \RuntimeException('订阅请求发送失败');
            }

            // 记录订阅信息到设备（包含 subscription_id）
            $device->addSubscription($eventType, $subscriptionId, $expires, [
                'start_priority' => $startAlarmPriority,
                'end_priority' => $endAlarmPriority,
                'alarm_method' => $alarmMethod
            ]);

            $this->logger->info('发送报警订阅成功', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
                'expires' => $expires,
                'subscription_id' => $subscriptionId,
                'start_priority' => $startAlarmPriority,
                'end_priority' => $endAlarmPriority
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('发送报警订阅失败', [
                'device_id' => $device->deviceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 发送移动位置订阅
     */
    public function sendSubscribeMobilePosition(Device $device, int $expires = 3600, int $interval = 5): void
    {
        try {
            $eventType = 'MobilePosition';
            $deviceId = $device->deviceId;

            // 构造目标 SIP URI
            $toUri = "sip:{$deviceId}@{$device->ip}:{$device->port}";

            // 构造 GB28181 移动位置订阅 XML 消息体
            $sn = time();
            $xmlBody = "<?xml version=\"1.0\" encoding=\"GB2312\"?>\r\n";
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
            $device->addSubscription($eventType, $subscriptionId, $expires, ['interval' => $interval]);

            $this->logger->info('发送移动位置订阅成功', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
                'expires' => $expires,
                'subscription_id' => $subscriptionId,
                'interval' => $interval
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('发送移动位置订阅失败', [
                'device_id' => $device->deviceId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
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

            $this->logger->info('取消目录订阅成功', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
                'subscription_id' => $subscriptionId,
                'result' => $result
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('取消目录订阅失败', [
                'device_id' => $device->deviceId,
                'error' => $e->getMessage()
            ]);
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

            $this->logger->info('取消报警订阅成功', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
                'subscription_id' => $subscriptionId,
                'result' => $result
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('取消报警订阅失败', [
                'device_id' => $device->deviceId,
                'error' => $e->getMessage()
            ]);
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

            $this->logger->info('取消移动位置订阅成功', [
                'device_id' => $deviceId,
                'event_type' => $eventType,
                'subscription_id' => $subscriptionId,
                'result' => $result
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('取消移动位置订阅失败', [
                'device_id' => $device->deviceId,
                'error' => $e->getMessage()
            ]);
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
                $scale = $options['scale'] ?? 1.0;
                $lines[] = "Scale: {$scale}";
                break;

            case 'reverse':
                // 倒放（GB28181 B.2.8）
                // PLAY + Scale: -1（负数表示倒放）
                // 标准：至少支持 -1（一倍速倒放）
                $lines[] = "PLAY RTSP/1.0";
                $lines[] = "CSeq: {$cseq}";
                $scale = $options['scale'] ?? -1.0;
                $lines[] = "Scale: {$scale}";
                
                // 可选：指定倒放起点
                // Range: npt=600-120 表示从第600秒倒放到第120秒
                if (isset($options['range'])) {
                    $lines[] = "Range: {$options['range']}";
                }
                break;

            case 'fast_forward':
                // 快进（向后兼容旧 API）
                // 实际就是正倍速 > 1
                $lines[] = "PLAY RTSP/1.0";
                $lines[] = "CSeq: {$cseq}";
                $speed = $options['speed'] ?? 2;
                $lines[] = "Scale: {$speed}";
                break;

            case 'slow_forward':
                // 慢放（向后兼容旧 API）
                // 实际就是正倍速 < 1
                $lines[] = "PLAY RTSP/1.0";
                $lines[] = "CSeq: {$cseq}";
                $speed = $options['speed'] ?? 2;
                $scale = 1.0 / $speed;
                $lines[] = "Scale: {$scale}";
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
}
