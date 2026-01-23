<?php

namespace CoreW\Sdk\PSipGateway;


use Illuminate\Redis\Connections\Connection;
use Redis;

/**
 * GB28181 客户端 SDK
 *
 * 通过 Redis 队列向信号网关发送命令
 */
class Gb28181Client
{
    private string $queueName = 'gb28181:commands';


    public function __construct(private Connection|Redis $redis, private array $gatewayConfig)
    {
    }

    /**
     * 发送命令到网关
     *
     * @param string $deviceId 设备ID (20位)
     * @param string $action 操作类型
     * @param array $params 参数
     * @return bool
     */
    public function sendCommand(string $deviceId, string $action, array $params = []): bool
    {
        if (!$this->checkGatewayIsRunning()) {
            throw new \RuntimeException("Gateway is not running");
        }

        $requestId = uniqid('req_', true);

        $command = [
            'request_id' => $requestId,
            'action' => $action,
            'device_id' => $deviceId,
            'channel_id' => $params['channel_id'] ?? $deviceId,
            'timestamp' => time(),
            'params' => $params
        ];

        try {
            // 推送到 Redis 队列
            $result = $this->redis->lPush($this->queueName, json_encode($command, JSON_UNESCAPED_UNICODE));
            return $result !== false;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to send command: " . $e->getMessage());
        }
    }

    /**
     * 更新设备信息:主要在于GateWay里面的DeviceManager
     * @param string $deviceId
     * @param array $data
     * @return bool
     */
    public function deviceUpdate(string $deviceId, array $data)
    {
        return $this->sendCommand($deviceId, 'device_update', $data);
    }


    /**
     * 查询设备目录
     * @param string $deviceId
     * @return bool
     */
    public function queryCatalog(string $deviceId): bool
    {
        return $this->sendCommand($deviceId, 'query_catalog');
    }

    /**
     * 查询设备信息
     * @param string $deviceId
     * @return bool
     */
    public function queryDeviceInfo(string $deviceId): bool
    {
        return $this->sendCommand($deviceId, 'query_device_info');
    }


    /**
     * 查询设备状态
     */
    public function queryDeviceStatus(string $deviceId): bool
    {
        return $this->sendCommand($deviceId, 'query_device_status');
    }

    /**
     * 查询录像文件
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $startTime 开始时间 (格式: 2024-12-01T00:00:00)
     * @param string $endTime 结束时间
     * @param string $type 录像类型 (all/time/alarm/manual)
     */
    public function queryRecord(
        string $deviceId,
        string $channelId,
        string $startTime,
        string $endTime,
        string $type = 'all'
    ): bool
    {
        return $this->sendCommand($deviceId, 'query_record', [
            'channel_id' => $channelId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'type' => $type
        ]);
    }

    /**
     * 开始实时视频
     *
     * 注意：ssrc、rtp_port、tcp_mode 由 API 项目的 Controller 层分配后传入
     * 这里不负责分配,只负责发送命令
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $ssrc 平台SSRC (由数据库分配)
     * @param int $zlmPort ZLM端口 (由ZLM分配)
     * @param int $tcpMode TCP模式 (0=UDP, 1=TCP被动, 2=TCP主动)
     * @param string|null $streamId ZLM流ID (用于标识和管理流)
     * @param string|null $streamIp 收流IP (媒体服务器IP，用于SDP中的c=行)
     * @param bool $seniorSdp 是否扩展SDP
     */
    public function startLiveVideo(
        string  $deviceId,
        string  $channelId,
        string  $ssrc,
        int     $zlmPort,
        int     $tcpMode = 1,
        ?string $streamId = null,
        ?string $streamIp = null,
        bool $seniorSdp  = false
    ): bool
    {
        return $this->sendCommand($deviceId, 'start_live_video', [
            'channel_id' => $channelId,
            'ssrc' => $ssrc,
            'rtp_port' => $zlmPort,
            'tcp_mode' => $tcpMode,
            'stream_id' => $streamId,
            'stream_ip' => $streamIp,
            'senior_sdp' => $seniorSdp
        ]);
    }

    /**
     * 停止实时视频
     */
    public function stopLiveVideo(string $deviceId, string $channelId, string $streamId): bool
    {
        return $this->sendCommand($deviceId, 'stop_live_video', [
            'channel_id' => $channelId,
            'stream_id' => $streamId
        ]);
    }

    /**
     * 开始录像回放
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @param string $ssrc 平台SSRC
     * @param int $zlmPort ZLM端口
     * @param int $tcpMode TCP模式
     * @param string|null $streamId ZLM流ID (用于标识和管理流)
     * @param string|null $streamIp 收流IP (媒体服务器IP，用于SDP中的c=行)
     */
    public function startPlayback(
        string  $deviceId,
        string  $channelId,
        string  $startTime,
        string  $endTime,
        string  $ssrc,
        int     $zlmPort,
        int     $tcpMode = 1,
        ?string $streamId = null,
        ?string $streamIp = null
    ): bool
    {
        return $this->sendCommand($deviceId, 'start_playback', [
            'channel_id' => $channelId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'ssrc' => $ssrc,
            'rtp_port' => $zlmPort,
            'tcp_mode' => $tcpMode,
            'stream_id' => $streamId,
            'stream_ip' => $streamIp
        ]);
    }

    /**
     * 停止录像回放
     */
    public function stopPlayback(string $deviceId, string $channelId, string $streamId): bool
    {
        return $this->sendCommand($deviceId, 'stop_playback', [
            'channel_id' => $channelId,
            'stream_id' => $streamId
        ]);
    }

    /**
     * 回放控制
     * 
     * 支持的操作:
     * - play: 正常播放
     * - pause: 暂停
     * - fast_forward: 快进 (speed: 2=2倍速, 4=4倍速)
     * - slow_forward: 慢放 (speed: 1=0.5倍速, 2=0.25倍速)
     * - seek: 拖动到指定时间
     * - scale: 缩放
     * 
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $action 操作类型
     * @param int $speed 倍速（用于快进/慢放）
     * @param string|null $seekTime 拖动时间（ISO8601格式，用于seek操作）
     * @param float $scale 缩放比例（用于scale操作）
     * @return bool
     */
    public function playbackControl(
        string $deviceId,
        string $channelId,
        string $streamId,
        string $action,
        int|float $speed = 1,
        ?string $seekTime = null,
        float $scale = 1.0
    ): bool {
        return $this->sendCommand($deviceId, 'playback_control', [
            'channel_id' => $channelId,
            'stream_id' => $streamId,
            'action' => $action,
            'speed' => $speed,
            'seek_time' => $seekTime,
            'scale' => $scale
        ]);
    }

    /**
     * 开始录像下载
     * 
     * 与普通回放的区别：
     * - session_name = 'Download' (而非 'Playback')
     * - 用于将录像下载为文件
     * 
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $startTime 开始时间
     * @param string $endTime 结束时间
     * @param string $ssrc 平台SSRC
     * @param int $zlmPort ZLM端口
     * @param int $tcpMode TCP模式
     * @param string|null $streamId ZLM流ID
     * @param string|null $streamIp 收流IP
     * @param int $downloadSpeed 下载倍速（1-4）
     * @return bool
     */
    public function startDownload(
        string $deviceId,
        string $channelId,
        string $startTime,
        string $endTime,
        string $ssrc,
        int $zlmPort,
        int $tcpMode = 1,
        ?string $streamId = null,
        ?string $streamIp = null,
        int $downloadSpeed = 1
    ): bool {
        return $this->sendCommand($deviceId, 'download_record', [
            'channel_id' => $channelId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'ssrc' => $ssrc,
            'rtp_port' => $zlmPort,
            'tcp_mode' => $tcpMode,
            'stream_id' => $streamId,
            'stream_ip' => $streamIp,
            'download_speed' => $downloadSpeed
        ]);
    }

    /**
     * PTZ 云台控制
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $command 控制命令 (up/down/left/right/zoom_in/zoom_out/stop)
     * @param int $speed 速度 (1-255)
     */
    public function ptzControl(
        string $deviceId,
        string $channelId,
        string $command,
        int    $speed = 5
    ): bool
    {
        return $this->sendCommand($deviceId, 'ptz_control', [
            'channel_id' => $channelId,
            'command' => $command,
            'speed' => $speed
        ]);
    }

    /**
     * 预置位控制
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $action 操作类型 (set/call/delete)
     * @param int $presetId 预置位编号 (1-255)
     * @return bool
     */
    public function presetControl(
        string $deviceId,
        string $channelId,
        string $action,
        int    $presetId
    ): bool
    {
        return $this->sendCommand($deviceId, 'preset_' . $action, [
            'channel_id' => $channelId,
            'preset_id' => $presetId
        ]);
    }

    /**
     * GB28181-2022: 设备升级
     *
     * @param string $deviceId 设备ID
     * @param string $manufacturer 制造商
     * @param string $firmware 固件版本
     * @return array 返回命令信息 (注意: 这里返回数组以便调用方获取 session_id 等信息)
     */
    public function deviceUpgrade(string $deviceId, string $manufacturer, string $firmware): array
    {
        $sessionId = strtoupper(md5(uniqid() . microtime(true)));
        $sn = rand(1, 99999999);

        $success = $this->sendCommand($deviceId, 'device_upgrade', [
            'manufacturer' => $manufacturer,
            'firmware' => $firmware,
            'session_id' => $sessionId,
            'sn' => $sn
        ]);

        return [
            'success' => $success,
            'session_id' => $sessionId,
            'sn' => $sn
        ];
    }

    /**
     * GB28181-2022: 图像抓拍
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $imageFormat 图片格式 (JPEG/PNG/BMP)
     * @return array 返回命令信息
     */
    public function snapshot(string $deviceId, string $channelId, string $imageFormat = 'JPEG'): array
    {
        $sessionId = strtoupper(md5(uniqid() . microtime(true)));
        $sn = rand(1, 99999999);

        $success = $this->sendCommand($deviceId, 'snapshot', [
            'channel_id' => $channelId,
            'session_id' => $sessionId,
            'image_format' => $imageFormat,
            'sn' => $sn
        ]);

        return [
            'success' => $success,
            'session_id' => $sessionId,
            'sn' => $sn,
            'image_format' => $imageFormat
        ];
    }

    /**
     * 订阅目录变更
     *
     * @param string $deviceId 设备ID
     * @param array $params 参数
     *   - expires: 订阅有效期（秒），默认3600
     * @return array 返回命令信息
     */
    public function subscribeCatalog(string $deviceId, array $params = []): array
    {
        $expires = $params['expires'] ?? 3600;

        $success = $this->sendCommand($deviceId, 'subscribe_catalog', [
            'expires' => $expires,
        ]);

        return [
            'success' => $success,
            'device_id' => $deviceId,
            'event_type' => 'Catalog',
            'expires' => $expires,
        ];
    }

    /**
     * 订阅报警
     *
     * @param string $deviceId 设备ID
     * @param array $params 参数
     *   - expires: 订阅有效期（秒），默认3600
     *   - start_priority: 报警最低优先级（0-4），默认0
     *   - end_priority: 报警最高优先级（0-4），默认4
     *   - alarm_method: 报警方式（1=电话,2=短信,3=邮件,4=APP,5=客户端），可选
     * @return array 返回命令信息
     */
    public function subscribeAlarm(string $deviceId, array $params = []): array
    {
        $expires = $params['expires'] ?? 3600;
        $startPriority = $params['start_priority'] ?? 0;
        $endPriority = $params['end_priority'] ?? 4;
        $alarmMethod = $params['alarm_method'] ?? null;

        $cmdParams = [
            'expires' => $expires,
            'start_priority' => $startPriority,
            'end_priority' => $endPriority,
        ];

        if ($alarmMethod !== null) {
            $cmdParams['alarm_method'] = $alarmMethod;
        }

        $success = $this->sendCommand($deviceId, 'subscribe_alarm', $cmdParams);

        return [
            'success' => $success,
            'device_id' => $deviceId,
            'event_type' => 'Alarm',
            'expires' => $expires,
        ];
    }

    /**
     * 订阅移动位置
     *
     * @param string $deviceId 设备ID
     * @param array $params 参数
     *   - expires: 订阅有效期（秒），默认3600
     *   - interval: 位置上报间隔（秒），默认5
     * @return array 返回命令信息
     */
    public function subscribeMobilePosition(string $deviceId, array $params = []): array
    {
        $expires = $params['expires'] ?? 3600;
        $interval = $params['interval'] ?? 5;

        $success = $this->sendCommand($deviceId, 'subscribe_mobile_position', [
            'expires' => $expires,
            'interval' => $interval,
        ]);

        return [
            'success' => $success,
            'device_id' => $deviceId,
            'event_type' => 'MobilePosition',
            'expires' => $expires,
            'interval' => $interval,
        ];
    }

    /**
     * 取消目录订阅
     *
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function unsubscribeCatalog(string $deviceId): bool
    {
        return $this->sendCommand($deviceId, 'unsubscribe_catalog');
    }

    /**
     * 取消报警订阅
     *
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function unsubscribeAlarm(string $deviceId): bool
    {
        return $this->sendCommand($deviceId, 'unsubscribe_alarm');
    }

    /**
     * 取消移动位置订阅
     *
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function unsubscribeMobilePosition(string $deviceId): bool
    {
        return $this->sendCommand($deviceId, 'unsubscribe_mobile_position');
    }

    private function checkGatewayIsRunning(): bool
    {
        $listenAddr = $this->gatewayConfig['listen_addr'];
        if ($this->gatewayConfig['listen_addr'] === '0.0.0.0') {
            $listenAddr = '127.0.0.1';
        }

        if ($listenAddr === '127.0.0.1') {
            $result = $this->checkPortProtocol($this->gatewayConfig['sip_port']);

            return $result['tcp'] || $result['udp'];
        }

        if ($this->isTcpOpen($listenAddr, $this->gatewayConfig['sip_port'])) {
            return true;
        }

        if ($this->isUdpOpen($listenAddr, $this->gatewayConfig['sip_port'])) {
            return true;
        }

        return false;
    }


    private function checkPortProtocol($port): array
    {
        $result = [
            'tcp' => false,
            'udp' => false,
        ];

        // TCP check
        $tcp = shell_exec("lsof -iTCP:$port -sTCP:LISTEN 2>/dev/null");
        if (!empty($tcp)) {
            $result['tcp'] = true;
        }

        // UDP check
        $udp = shell_exec("lsof -iUDP:$port 2>/dev/null");
        if (!empty($udp)) {
            $result['udp'] = true;
        }

        return $result;
    }

    private function isUdpOpen($host, $port): bool
    {
        $cmd = "nc -vzu {$host} {$port} 2>&1";
        exec($cmd, $output, $status);

        $msg = implode("\n", $output);

        if (str_contains($msg, 'succeeded')) {
            return true;  // UDP 端口可达（有响应）
        }

        if (str_contains($msg, 'refused')) {
            return false; // 确认端口关闭
        }

        // 无响应 → 不确定，可能被防火墙 DROP
        return false;
    }



    private function isTcpOpen($host, $port, $timeout = 1): bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

}