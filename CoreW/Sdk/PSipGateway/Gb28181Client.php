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


    public function __construct(private Connection|Redis $redis)
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
     * 查询设备目录
     */
    public function queryCatalog(string $deviceId): bool
    {
        return $this->sendCommand($deviceId, 'query_catalog');
    }

    /**
     * 查询设备信息
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
    ): bool {
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
     * 注意：ssrc、zlm_port、tcp_mode 由 API 项目的 Controller 层分配后传入
     * 这里不负责分配,只负责发送命令
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $ssrc 平台SSRC (由数据库分配)
     * @param int $zlmPort ZLM端口 (由ZLM分配)
     * @param int $tcpMode TCP模式 (0=UDP, 1=TCP被动, 2=TCP主动)
     * @param string|null $streamId ZLM流ID (用于标识和管理流)
     */
    public function startLiveVideo(
        string $deviceId,
        string $channelId,
        string $ssrc,
        int $zlmPort,
        int $tcpMode = 1,
        ?string $streamId = null
    ): bool {
        return $this->sendCommand($deviceId, 'start_live_video', [
            'channel_id' => $channelId,
            'ssrc' => $ssrc,
            'zlm_port' => $zlmPort,
            'tcp_mode' => $tcpMode,
            'stream_id' => $streamId
        ]);
    }

    /**
     * 停止实时视频
     */
    public function stopLiveVideo(string $deviceId, string $channelId): bool
    {
        return $this->sendCommand($deviceId, 'stop_live_video', [
            'channel_id' => $channelId
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
     */
    public function startPlayback(
        string $deviceId,
        string $channelId,
        string $startTime,
        string $endTime,
        string $ssrc,
        int $zlmPort,
        int $tcpMode = 1,
        ?string $streamId = null
    ): bool {
        return $this->sendCommand($deviceId, 'start_playback', [
            'channel_id' => $channelId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'ssrc' => $ssrc,
            'zlm_port' => $zlmPort,
            'tcp_mode' => $tcpMode,
            'stream_id' => $streamId
        ]);
    }

    /**
     * 停止录像回放
     */
    public function stopPlayback(string $deviceId, string $channelId): bool
    {
        return $this->sendCommand($deviceId, 'stop_playback', [
            'channel_id' => $channelId
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
        int $speed = 5
    ): bool {
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
        int $presetId
    ): bool {
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
}