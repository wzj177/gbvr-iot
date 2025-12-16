<?php

/**
 * GB28181 命令分发器
 *
 * 职责：
 * 1. 接收 Redis 队列的命令请求
 * 2. 根据 action 分发到对应的处理方法
 * 3. 调用 QuerySender 和 SIP 服务器执行实际操作
 *
 * 支持的操作：
 * - start_live_video: 开始实时视频
 * - stop_live_video: 停止实时视频
 * - start_playback: 开始录像回放
 * - query_device_info: 查询设备信息
 * - query_device_status: 查询设备状态
 * - query_record: 查询录像文件
 * - ptz_control: PTZ 云台控制
 * - query_catalog: 查询设备目录(已实现)
 */

namespace Gb28181\GateWay\Message;

use Gb28181\GateWay\Device\DeviceManager;
use Gb28181\GateWay\Libs\Logger;

class CommandDispatcher
{
    private \ExoSip $sipServer;
    private QuerySender $querySender;
    private DeviceManager $deviceManager;
    private array $config;
    private array $activeSessions = [];  // 活跃的会话(INVITE)
    private Logger $logger;

    /**
     * 构造函数
     */
    public function __construct(\ExoSip $sipServer, QuerySender $querySender, DeviceManager $deviceManager, array $config = [])
    {
        $this->sipServer = $sipServer;
        $this->querySender = $querySender;
        $this->deviceManager = $deviceManager;
        $this->config = array_merge([
            'media_server_ip' => '192.168.1.100',
            'media_server_port_start' => 30000,
            'media_server_port_end' => 40000,
            'server_id' => '34020000002000000001',  // 默认平台ID
            'debug' => false,
        ], $config);
        $this->logger = Logger::getInstance();
    }

    /**
     * 分发命令
     *
     * @param array $command 命令数据
     * @return array 执行结果
     */
    public function dispatch(array $command): array
    {
        $action = $command['action'] ?? '';
        $deviceId = $command['device_id'] ?? '';
        $channelId = $command['channel_id'] ?? $deviceId;
        $params = $command['params'] ?? [];
        $requestId = $command['request_id'] ?? uniqid();

        $this->log("Dispatch command: {$action} (Device: {$deviceId}, Channel: {$channelId})");

        // 检查设备是否在线
        $device = $this->deviceManager->getDevice($deviceId);
        if (!$device) {
            return $this->errorResponse($requestId, "Device not found: {$deviceId}");
        }

        if (!$device['registered']) {
            return $this->errorResponse($requestId, "Device not registered: {$deviceId}");
        }

        // 获取设备地址
        $deviceIp = $device['received_ip'] ?? $device['ip'] ?? '127.0.0.1';
        $devicePort = $device['received_port'] ?? $device['port'] ?? 5060;

        // 根据 action 分发
        try {
            return match ($action) {
                'start_live_video' => $this->handleStartLiveVideo($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'stop_live_video' => $this->handleStopLiveVideo($requestId, $deviceId, $channelId),
                'start_playback' => $this->handleStartPlayback($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'stop_playback' => $this->handleStopPlayback($requestId, $deviceId, $channelId),
                'query_device_info' => $this->handleQueryDeviceInfo($requestId, $deviceId, $deviceIp, $devicePort),
                'query_device_status' => $this->handleQueryDeviceStatus($requestId, $deviceId, $deviceIp, $devicePort),
                'query_record' => $this->handleQueryRecord($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'ptz_control' => $this->handlePtzControl($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'preset_set' => $this->handlePresetSet($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'preset_call' => $this->handlePresetCall($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'preset_delete' => $this->handlePresetDelete($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'device_upgrade' => $this->handleDeviceUpgrade($requestId, $deviceId, $deviceIp, $devicePort, $params),
                'snapshot' => $this->handleSnapshot($requestId, $deviceId, $channelId, $deviceIp, $devicePort, $params),
                'query_catalog' => $this->handleQueryCatalog($requestId, $deviceId, $deviceIp, $devicePort),
                'query_mobile_position' => $this->handleQueryMobilePosition($requestId, $deviceId, $deviceIp, $devicePort, $params),
                default => $this->errorResponse($requestId, "Unknown action: {$action}"),
            };
        } catch (\Exception $e) {
            return $this->errorResponse($requestId, "Exception: {$e->getMessage()}");
        }
    }

    /**
     * 开始实时视频
     * 流程: INVITE -> 200 OK -> ACK
     *
     * 注意：SSRC和ZLM端口由gbvr-iot分配，通过params传入
     */
    private function handleStartLiveVideo(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params): array
    {
        $this->log("Start live video: {$channelId}");
        
        // 调试:打印接收到的params
        if ($this->config['debug']) {
            $this->log("Received params: " . json_encode($params, JSON_UNESCAPED_UNICODE));
        }

        //  从params获取SSRC（由gbvr-iot数据库分配）
        $ssrc = $params['ssrc'] ?? null;
        if (!$ssrc) {
            return $this->errorResponse($requestId, "Missing SSRC from API, params must include 'ssrc'");
        }

        //  从params获取ZLM端口（由gbvr-iot的ZLM服务分配）
        $zlmPort = $params['zlm_port'] ?? null;
        if (!$zlmPort) {
            return $this->errorResponse($requestId, "Missing zlm_port from API, params must include 'zlm_port'");
        }
        
        // TCP 模式
        $tcpMode = $params['tcp_mode'] ?? 0;

        // 可选：stream_id用于会话追踪
        $streamId = $params['stream_id'] ?? null;

        // 构建 SDP（使用传入的SSRC和端口）
        $sdp = SdpBuilder::buildLiveVideoSdp(
            serverId: $this->config['server_id'],
            mediaIp: $this->config['media_server_ip'],
            mediaPort: $zlmPort,
            ssrc: $ssrc,
            tcpMode: $tcpMode
        );
        
        // 调试:打印生成的SDP
        if ($this->config['debug']) {
            $this->log("Generated SDP:\n{$sdp}");
        }

        // 发送 INVITE
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $subject = "{$channelId}:{$channelId},{$this->config['server_id']}:0";

        $dialogId = $this->sipServer->sendInvite($targetUri, $sdp, [
            'Subject' => $subject,
            'Content-Type' => 'application/sdp'
        ]);

        if ($dialogId === false) {
            return $this->errorResponse($requestId, "Failed to send INVITE");
        }

        // 保存会话信息
        $sessionKey = "{$deviceId}:{$channelId}:live";
        $this->activeSessions[$sessionKey] = [
            'request_id' => $requestId,
            'dialog_id' => $dialogId,
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'type' => 'live',
            'ssrc' => $ssrc,
            'zlm_port' => $zlmPort,
            'stream_id' => $streamId,
            'started_at' => time(),
        ];

        $this->log("Live video session created: {$sessionKey} (Dialog: {$dialogId}, SSRC: {$ssrc}, ZLM Port: {$zlmPort})");

        return [
            'success' => true,
            'request_id' => $requestId,
            'dialog_id' => $dialogId,
            'ssrc' => $ssrc,
            'zlm_port' => $zlmPort,
            'stream_id' => $streamId,
        ];
    }

    /**
     * 停止实时视频
     * 流程: BYE -> 200 OK
     */
    private function handleStopLiveVideo(string $requestId, string $deviceId, string $channelId): array
    {
        $this->log("Stop live video: {$channelId}");

        $sessionKey = "{$deviceId}:{$channelId}:live";
        $session = $this->activeSessions[$sessionKey] ?? null;

        if (!$session) {
            return $this->errorResponse($requestId, "No active live video session");
        }

        // 发送 BYE
        $result = $this->sipServer->sendBye($session['dialog_id']);

        if ($result === false) {
            return $this->errorResponse($requestId, "Failed to send BYE");
        }

        // 移除会话
        unset($this->activeSessions[$sessionKey]);

        $this->log("Live video session stopped: {$sessionKey}");

        return [
            'success' => true,
            'request_id' => $requestId,
        ];
    }

    /**
     * 开始录像回放
     * 流程: INVITE(带录像时间) -> 200 OK -> ACK
     */
    private function handleStartPlayback(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params): array
    {
        $this->log("Start playback: {$channelId}");

        // 验证参数
        if (!isset($params['start_time']) || !isset($params['end_time'])) {
            return $this->errorResponse($requestId, "Missing start_time or end_time");
        }

        $startTime = $params['start_time'];  // 格式: 2024-01-01T00:00:00
        $endTime = $params['end_time'];

        //  从params获取SSRC和ZLM端口
        $ssrc = $params['ssrc'] ?? null;
        $zlmPort = $params['zlm_port'] ?? null;

        if (!$ssrc || !$zlmPort) {
            return $this->errorResponse($requestId, "Missing ssrc or zlm_port from API");
        }
        
        // TCP 模式
        $tcpMode = $params['tcp_mode'] ?? 0;

        $streamId = $params['stream_id'] ?? null;

        // 构建 SDP (回放使用 Playback)
        // 注意: GB28181 录像回放的时间参数通常在 INVITE 的 XML body 中传递,
        // SDP 的 t= 行仍然使用 0 0 表示永久会话
        $sdp = SdpBuilder::buildPlaybackSdp(
            serverId: $this->config['server_id'],
            mediaIp: $this->config['media_server_ip'],
            mediaPort: $zlmPort,
            ssrc: $ssrc,
            startTime: 0,  // SDP 中通常为 0 0
            endTime: 0,
            tcpMode: $tcpMode
        );

        // 发送 INVITE
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $subject = "{$channelId}:{$channelId},{$this->config['server_id']}:0";

        $dialogId = $this->sipServer->sendInvite($targetUri, $sdp, [
            'Subject' => $subject,
            'Content-Type' => 'application/sdp'
        ]);

        if ($dialogId === false) {
            return $this->errorResponse($requestId, "Failed to send INVITE");
        }

        // 保存会话信息
        $sessionKey = "{$deviceId}:{$channelId}:playback";
        $this->activeSessions[$sessionKey] = [
            'request_id' => $requestId,
            'dialog_id' => $dialogId,
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'type' => 'playback',
            'ssrc' => $ssrc,
            'zlm_port' => $zlmPort,
            'stream_id' => $streamId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'started_at' => time(),
        ];

        $this->log("Playback session created: {$sessionKey} (Dialog: {$dialogId})");

        return [
            'success' => true,
            'request_id' => $requestId,
            'dialog_id' => $dialogId,
            'ssrc' => $ssrc,
            'zlm_port' => $zlmPort,
            'stream_id' => $streamId,
        ];
    }

    /**
     * 停止录像回放
     */
    private function handleStopPlayback(string $requestId, string $deviceId, string $channelId): array
    {
        $this->log("Stop playback: {$channelId}");

        $sessionKey = "{$deviceId}:{$channelId}:playback";
        $session = $this->activeSessions[$sessionKey] ?? null;

        if (!$session) {
            return $this->errorResponse($requestId, "No active playback session");
        }

        // 发送 BYE
        $result = $this->sipServer->sendBye($session['dialog_id']);

        if ($result === false) {
            return $this->errorResponse($requestId, "Failed to send BYE");
        }

        // 释放资源
        $this->releaseMediaPort($session['media_port']);
        unset($this->activeSessions[$sessionKey]);

        $this->log("Playback session stopped: {$sessionKey}");

        return [
            'success' => true,
            'request_id' => $requestId,
        ];
    }

    /**
     * 查询设备信息
     */
    private function handleQueryDeviceInfo(string $requestId, string $deviceId, string $deviceIp, int $devicePort): array
    {
        $this->log("Query device info: {$deviceId}");

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->queryDeviceInfo($targetUri, $deviceId);

        return [
            'success' => $result,
            'request_id' => $requestId,
            'message' => $result ? 'Query sent' : 'Failed to send query',
        ];
    }

    /**
     * 查询设备状态
     */
    private function handleQueryDeviceStatus(string $requestId, string $deviceId, string $deviceIp, int $devicePort): array
    {
        $this->log("Query device status: {$deviceId}");

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->queryDeviceStatus($targetUri, $deviceId);

        return [
            'success' => $result,
            'request_id' => $requestId,
            'message' => $result ? 'Query sent' : 'Failed to send query',
        ];
    }

    /**
     * 查询录像文件
     */
    private function handleQueryRecord(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params): array
    {
        $this->log("Query record: {$channelId}");

        // 验证参数
        if (!isset($params['start_time']) || !isset($params['end_time'])) {
            return $this->errorResponse($requestId, "Missing start_time or end_time");
        }

        $startTime = $params['start_time'];  // 格式: 2024-01-01T00:00:00
        $endTime = $params['end_time'];
        $type = $params['type'] ?? 'all';  // all, time, alarm, manual

        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->queryRecordInfo($targetUri, $channelId, $startTime, $endTime, $type);

        return [
            'success' => $result,
            'request_id' => $requestId,
            'message' => $result ? 'Query sent' : 'Failed to send query',
        ];
    }

    /**
     * PTZ 云台控制
     */
    private function handlePtzControl(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params): array
    {
        $this->log("PTZ control: {$channelId}");

        // 验证参数
        if (!isset($params['command'])) {
            return $this->errorResponse($requestId, "Missing PTZ command");
        }

        $ptzCmd = $this->buildPtzCommand($params);

        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success' => $result,
            'request_id' => $requestId,
            'message' => $result ? 'PTZ command sent' : 'Failed to send PTZ command',
        ];
    }

    /**
     * 查询设备目录
     */
    private function handleQueryCatalog(string $requestId, string $deviceId, string $deviceIp, int $devicePort): array
    {
        $this->log("Query catalog: {$deviceId}");

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->queryCatalog($targetUri, $deviceId);

        return [
            'success' => $result,
            'request_id' => $requestId,
            'message' => $result ? 'Query sent' : 'Failed to send query',
        ];
    }

    /**
     * 构建 PTZ 控制命令
     * GB28181 PTZ 命令格式: A5 0F 01 [指令码] [水平速度] [垂直速度] [焦距速度高4位]0 [校验码]
     * 
     * 指令码(第4字节)位定义:
     * bit 0 (0x01): 右移
     * bit 1 (0x02): 左移
     * bit 2 (0x04): 下移
     * bit 3 (0x08): 上移
     * bit 4 (0x10): 放大
     * bit 5 (0x20): 缩小
     * 
     * 参考: GB/T 28181-2016 附录F, PtzCmd.cpp
     */
    private function buildPtzCommand(array $params): string
    {
        $command = $params['command'];  // up, down, left, right, zoom_in, zoom_out, stop
        $speed = $params['speed'] ?? 5;  // 1-255

        $cmdCode = 0;
        $horizontalSpeed = 0;
        $verticalSpeed = 0;
        $zoomSpeed = 0;

        switch ($command) {
            case 'right':
                $cmdCode = 0x01;  // bit 0: 右移
                $horizontalSpeed = $speed;
                break;
            case 'left':
                $cmdCode = 0x02;  // bit 1: 左移
                $horizontalSpeed = $speed;
                break;
            case 'down':
                $cmdCode = 0x04;  // bit 2: 下移
                $verticalSpeed = $speed;
                break;
            case 'up':
                $cmdCode = 0x08;  // bit 3: 上移
                $verticalSpeed = $speed;
                break;
            case 'zoom_in':
                $cmdCode = 0x10;  // bit 4: 放大
                $zoomSpeed = $speed & 0x0F;  // 焦距速度(第7字节高4位)
                break;
            case 'zoom_out':
                $cmdCode = 0x20;  // bit 5: 缩小
                $zoomSpeed = $speed & 0x0F;
                break;
            case 'stop':
                // 全部为0: 停止所有动作
                break;
        }

        // 第7字节: 高4位是焦距速度, 低4位固定为0
        $byte7 = ($zoomSpeed << 4) & 0xF0;

        // 计算校验码: (字节1 + 字节2 + ... + 字节7) % 256
        $checksum = (0xA5 + 0x0F + 0x01 + $cmdCode + $horizontalSpeed + $verticalSpeed + $byte7) % 0x100;

        // 构建完整命令: A5 0F 01 [指令码] [水平速度] [垂直速度] [焦距速度]0 [校验码]
        $ptzCmd = sprintf("A50F01%02X%02X%02X%02X%02X", 
            $cmdCode, 
            $horizontalSpeed, 
            $verticalSpeed, 
            $byte7, 
            $checksum
        );

        return $ptzCmd;
    }

    /**     * 构建预置位命令
     * 
     * GB28181 预置位命令:
     * - 0x81: 设置预置位
     * - 0x82: 调用预置位
     * - 0x83: 删除预置位
     * 
     * 格式: A5 0F 01 [指令码] 00 [预置位编号] 00 [校验码]
     */
    private function buildPresetCommand(string $action, int $presetId): string
    {
        $cmdCode = match($action) {
            'set' => 0x81,     // 设置预置位
            'call' => 0x82,    // 调用预置位
            'delete' => 0x83,  // 删除预置位
            default => 0x00
        };

        // 预置位ID范围: 1-255
        $presetId = max(1, min(255, $presetId));

        // 计算校验码
        $checksum = (0xA5 + 0x0F + 0x01 + $cmdCode + 0x00 + $presetId + 0x00) % 0x100;

        // 构建命令: A5 0F 01 [cmdCode] 00 [presetId] 00 [checksum]
        $presetCmd = sprintf("A50F01%02X00%02X00%02X", $cmdCode, $presetId, $checksum);

        return $presetCmd;
    }

    /**
     * 设置预置位
     */
    private function handlePresetSet(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params): array
    {
        $presetId = $params['preset_id'] ?? 1;
        $this->log("Set preset: {$channelId}, preset_id={$presetId}");

        $ptzCmd = $this->buildPresetCommand('set', $presetId);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success' => $result,
            'request_id' => $requestId,
            'message' => $result ? 'Preset set command sent' : 'Failed to send preset set command',
            'preset_id' => $presetId
        ];
    }

    /**
     * 调用预置位
     */
    private function handlePresetCall(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params): array
    {
        $presetId = $params['preset_id'] ?? 1;
        $this->log("Call preset: {$channelId}, preset_id={$presetId}");

        $ptzCmd = $this->buildPresetCommand('call', $presetId);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success' => $result,
            'request_id' => $requestId,
            'message' => $result ? 'Preset call command sent' : 'Failed to send preset call command',
            'preset_id' => $presetId
        ];
    }

    /**
     * 删除预置位
     */
    private function handlePresetDelete(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params): array
    {
        $presetId = $params['preset_id'] ?? 1;
        $this->log("Delete preset: {$channelId}, preset_id={$presetId}");

        $ptzCmd = $this->buildPresetCommand('delete', $presetId);
        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->querySender->ptzControl($targetUri, $channelId, $ptzCmd);

        return [
            'success' => $result,
            'request_id' => $requestId,
            'message' => $result ? 'Preset delete command sent' : 'Failed to send preset delete command',
            'preset_id' => $presetId
        ];
    }

    /**
     * GB28181-2022: 设备升级
     */
    private function handleDeviceUpgrade(string $requestId, string $deviceId, string $deviceIp, int $devicePort, array $params): array
    {
        $this->log("Device upgrade: {$deviceId}");

        $manufacturer = $params['manufacturer'] ?? '';
        $firmware = $params['firmware'] ?? '';

        if (!$manufacturer || !$firmware) {
            return $this->errorResponse($requestId, 'Missing manufacturer or firmware');
        }

        // 构建升级XML
        $sn = rand(1, 99999999);
        $xml = "<?xml version=\"1.0\" encoding=\"GB2312\"?>\r\n";
        $xml .= "<Control>\r\n";
        $xml .= "<CmdType>DeviceControl</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$deviceId}</DeviceID>\r\n";
        $xml .= "<DeviceUpgrade>\r\n";
        $xml .= "<Manufacturer>{$manufacturer}</Manufacturer>\r\n";
        $xml .= "<Firmware>{$firmware}</Firmware>\r\n";
        $xml .= "</DeviceUpgrade>\r\n";
        $xml .= "</Control>";

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success' => $result,
            'request_id' => $requestId,
            'message' => $result ? 'Upgrade command sent' : 'Failed to send upgrade command',
            'sn' => $sn
        ];
    }

    /**
     * GB28181-2022: 图像抓拍
     */
    private function handleSnapshot(string $requestId, string $deviceId, string $channelId, string $deviceIp, int $devicePort, array $params): array
    {
        $this->log("Snapshot: {$channelId}");

        $sessionId = $params['session_id'] ?? strtoupper(md5(uniqid()));
        $imageFormat = $params['image_format'] ?? 'JPEG';

        // 构建抓拍XML
        $sn = rand(1, 99999999);
        $xml = "<?xml version=\"1.0\" encoding=\"GB2312\"?>\r\n";
        $xml .= "<Control>\r\n";
        $xml .= "<CmdType>DeviceControl</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$channelId}</DeviceID>\r\n";
        $xml .= "<Snapshot>\r\n";
        $xml .= "<SessionID>{$sessionId}</SessionID>\r\n";
        $xml .= "<ImageFormat>{$imageFormat}</ImageFormat>\r\n";
        $xml .= "</Snapshot>\r\n";
        $xml .= "</Control>";

        $targetUri = "sip:{$channelId}@{$deviceIp}:{$devicePort}";
        $result = $this->sipServer->sendMessage($targetUri, $xml, 'Application/MANSCDP+xml');

        return [
            'success' => $result,
            'request_id' => $requestId,
            'message' => $result ? 'Snapshot command sent' : 'Failed to send snapshot command',
            'session_id' => $sessionId,
            'sn' => $sn
        ];
    }

    /**     * 分配媒体端口
     */
    private function allocateMediaPort(): int
    {
        // 简化实现:随机分配
        // 生产环境应该维护端口池
        static $lastPort = null;
        if ($lastPort === null) {
            $lastPort = $this->config['media_server_port_start'];
        }
        $lastPort += 2;  // RTP/RTCP 成对使用
        if ($lastPort > $this->config['media_server_port_end']) {
            $lastPort = $this->config['media_server_port_start'];
        }
        return $lastPort;
    }

    /**
     * 释放媒体端口
     */
    private function releaseMediaPort(int $port): void
    {
        // TODO: 实现端口池管理
        $this->log("Release media port: {$port}");
    }

    /**
     * 错误响应
     */
    private function errorResponse(string $requestId, string $error): array
    {
        $this->log("Error: {$error}", 'ERROR');
        return [
            'success' => false,
            'request_id' => $requestId,
            'error' => $error,
        ];
    }

    /**
     * 处理位置查询 (MobilePosition Query)
     * 
     * @param string $requestId 请求ID
     * @param string $deviceId 设备ID
     * @param string $deviceIp 设备IP
     * @param int $devicePort 设备端口
     * @param array $params 参数 ['interval' => 位置上报间隔(秒),可选]
     * @return array 处理结果
     */
    private function handleQueryMobilePosition(
        string $requestId,
        string $deviceId,
        string $deviceIp,
        int $devicePort,
        array $params
    ): array {
        $this->log("Query mobile position: {$deviceId}");

        $interval = $params['interval'] ?? null; // 上报间隔(秒),可选
        $sn = rand(1, 99999999);

        // 构建 MobilePosition 查询 XML
        $xml = "<?xml version=\"1.0\" encoding=\"GB2312\"?>\r\n";
        $xml .= "<Query>\r\n";
        $xml .= "<CmdType>MobilePosition</CmdType>\r\n";
        $xml .= "<SN>{$sn}</SN>\r\n";
        $xml .= "<DeviceID>{$deviceId}</DeviceID>\r\n";
        
        // 可选: 指定上报间隔
        if ($interval !== null) {
            $xml .= "<Interval>{$interval}</Interval>\r\n";
        }
        
        $xml .= "</Query>";

        $targetUri = "sip:{$deviceId}@{$deviceIp}:{$devicePort}";

        // 使用 QuerySender 发送 MESSAGE
        $result = $this->querySender->sendQuery($targetUri, $xml);

        if ($result) {
            $this->log("✓ Mobile position query sent");
            return [
                'success' => true,
                'request_id' => $requestId,
                'device_id' => $deviceId,
                'sn' => $sn,
                'interval' => $interval,
                'message' => 'Mobile position query command sent'
            ];
        }

        return $this->errorResponse($requestId, 'Failed to send mobile position query');
    }

    /**
     * 日志输出
     */
    private function log(string $message, string $level = 'INFO'): void
    {
        $time = date('Y-m-d H:i:s');
        echo "[{$time}] [{$level}] [CommandDispatcher] {$message}\n";
    }

    /**
     * 获取活跃会话
     */
    public function getActiveSessions(): array
    {
        return $this->activeSessions;
    }

    /**
     * 清理超时会话
     */
    public function cleanupTimeoutSessions(int $timeout = 3600): void
    {
        $now = time();
        foreach ($this->activeSessions as $key => $session) {
            if ($now - $session['started_at'] > $timeout) {
                $this->log("Cleanup timeout session: {$key}");
                $this->sipServer->sendBye($session['dialog_id']);
                $this->releaseMediaPort($session['media_port']);
                unset($this->activeSessions[$key]);
            }
        }
    }
}