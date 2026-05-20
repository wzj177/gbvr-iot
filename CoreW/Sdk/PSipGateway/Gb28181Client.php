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

    /**
     * gateway_id 解析器：传入 deviceId，返回 gateway_id 或 null
     * @var callable|null
     */
    private $gatewayIdResolver = null;


    public function __construct(private Connection|Redis $redis, private array $gatewayConfig)
    {
    }

    /**
     * 设置 gateway_id 解析器
     * 解析器签名: function(string $deviceId): ?string
     */
    public function setGatewayIdResolver(callable $resolver) : void
    {
        $this->gatewayIdResolver = $resolver;
    }

    /**
     * 发送命令到网关
     *
     * @param string $deviceId 设备ID (20位)
     * @param string $action 操作类型
     * @param array $params 参数
     * @return bool
     */
    public function sendCommand(string $deviceId, string $action, array $params = []) : bool
    {
        // 自动从解析器获取 gateway_id（集群模式）
        $gatewayId = $params['gateway_id'] ?? null;
        if (!$gatewayId && $this->gatewayIdResolver) {
            try {
                $gatewayId = ($this->gatewayIdResolver)($deviceId);
                if ($gatewayId) {
                    $params['gateway_id'] = $gatewayId;
                }
            } catch (\Throwable $e) {
                // 解析失败不影响命令发送
            }
        }

        $requestId = uniqid('req_', true);

        $command = [
            'request_id' => $requestId,
            'action'     => $action,
            'device_id'  => $deviceId,
            'channel_id' => $params['channel_id'] ?? $deviceId,
            'timestamp'  => time(),
            'params'     => $params,
        ];

        try {
            $queueName = $gatewayId
                ? "gb28181:commands:{$gatewayId}"
                : $this->queueName;

            // 推送到 Redis 队列
            $result = $this->redis->lPush($queueName, json_encode($command, JSON_UNESCAPED_UNICODE));
            return $result !== false;
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to send command: " . $e->getMessage());
        }
    }

    /**
     * 等待命令响应
     *
     * @param string $requestId 请求ID
     * @param int $timeout 超时时间(秒)
     * @return array 响应数据
     * @throws \RuntimeException
     */
    private function waitResponse(string $requestId, int $timeout = 5) : array
    {
        $responseKey = "gb28181:response:{$requestId}";

        // 使用 BRPOP 阻塞等待响应
        $response = $this->redis->brPop([$responseKey], $timeout);

        if (!$response || !isset($response[1])) {
            throw new \RuntimeException("Command timeout: no response from gateway after {$timeout}s");
        }

        $data = json_decode($response[1], true);

        if (!$data) {
            throw new \RuntimeException("Invalid response format from gateway");
        }

        // 清理响应键(可选,Redis会自动过期)
        try {
            $this->redis->del($responseKey);
        } catch (\Exception $e) {
            // 忽略删除失败
        }

        return $data;
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
    public function queryCatalog(string $deviceId) : bool
    {
        return $this->sendCommand($deviceId, 'query_catalog');
    }

    /**
     * 查询设备信息
     * @param string $deviceId
     * @return bool
     */
    public function queryDeviceInfo(string $deviceId) : bool
    {
        return $this->sendCommand($deviceId, 'query_device_info');
    }


    /**
     * 查询设备状态
     */
    public function queryDeviceStatus(string $deviceId) : bool
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
    ) : bool
    {
        return $this->sendCommand($deviceId, 'query_record', [
            'channel_id' => $channelId,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'type'       => $type,
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
        string $deviceId,
        string $channelId,
        string $ssrc,
        int $zlmPort,
        int $tcpMode = 1,
        ?string $streamId = null,
        ?string $streamIp = null,
        bool $seniorSdp = false
    ) : bool
    {
        return $this->sendCommand($deviceId, 'start_live_video', [
            'channel_id' => $channelId,
            'ssrc'       => $ssrc,
            'rtp_port'   => $zlmPort,
            'tcp_mode'   => $tcpMode,
            'stream_id'  => $streamId,
            'stream_ip'  => $streamIp,
            'senior_sdp' => $seniorSdp,
        ]);
    }

    /**
     * 停止实时视频
     */
    public function stopLiveVideo(string $deviceId, string $channelId, string $streamId) : bool
    {
        return $this->sendCommand($deviceId, 'stop_live_video', [
            'channel_id' => $channelId,
            'stream_id'  => $streamId,
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
        string $deviceId,
        string $channelId,
        string $startTime,
        string $endTime,
        string $ssrc,
        int $zlmPort,
        int $tcpMode = 1,
        ?string $streamId = null,
        ?string $streamIp = null
    ) : bool
    {
        return $this->sendCommand($deviceId, 'start_playback', [
            'channel_id' => $channelId,
            'start_time' => $startTime,
            'end_time'   => $endTime,
            'ssrc'       => $ssrc,
            'rtp_port'   => $zlmPort,
            'tcp_mode'   => $tcpMode,
            'stream_id'  => $streamId,
            'stream_ip'  => $streamIp,
        ]);
    }

    /**
     * 停止录像回放
     */
    public function stopPlayback(string $deviceId, string $channelId, string $streamId) : bool
    {
        return $this->sendCommand($deviceId, 'stop_playback', [
            'channel_id' => $channelId,
            'stream_id'  => $streamId,
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
    ) : bool
    {
        return $this->sendCommand($deviceId, 'playback_control', [
            'channel_id' => $channelId,
            'stream_id'  => $streamId,
            'action'     => $action,
            'speed'      => $speed,
            'seek_time'  => $seekTime,
            'scale'      => $scale,
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
    ) : bool
    {
        return $this->sendCommand($deviceId, 'download_record', [
            'channel_id'     => $channelId,
            'start_time'     => $startTime,
            'end_time'       => $endTime,
            'ssrc'           => $ssrc,
            'rtp_port'       => $zlmPort,
            'tcp_mode'       => $tcpMode,
            'stream_id'      => $streamId,
            'stream_ip'      => $streamIp,
            'download_speed' => $downloadSpeed,
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
    ) : bool
    {
        return $this->sendCommand($deviceId, 'ptz_control', [
            'channel_id' => $channelId,
            'command'    => $command,
            'speed'      => $speed,
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
    ) : bool
    {
        return $this->sendCommand($deviceId, 'preset_' . $action, [
            'channel_id' => $channelId,
            'preset_id'  => $presetId,
        ]);
    }

    /**
     * 发起语音广播（发送 Broadcast MESSAGE 通知到设备）
     *
     * 广播模式流程：
     * 1. 服务端发送 MESSAGE（CmdType=Broadcast）通知设备
     * 2. 设备处理后主动发送 INVITE 给服务端
     * 3. 网关回复 200 OK（携带 SDP，包含 ZLM 端口）
     *
     * @param array $session 会话信息（包含 ssrc, rtp_local_port, media_server_ip 等）
     * @return array
     */
    public function startAudioBroadcast(array $session) : array
    {
        $this->sendCommand($session['device_id'], 'voice_broadcast', $session);

        return [
            'success'    => true,
            'device_id'  => $session['device_id'],
            'channel_id' => $session['channel_id'],
            'mode'       => $session['mode'] ?? 'broadcast',
            'pending'    => true,
        ];
    }

    /**
     * 发起语音对讲（发送 INVITE 到设备）
     *
     * @param array $session 会话信息
     * @return array
     */
    public function startVoiceTalk(array $session) : array
    {
        $this->sendCommand($session['device_id'], 'voice_invite', $session);

        return [
            'success'    => true,
            'device_id'  => $session['device_id'],
            'channel_id' => $session['channel_id'],
            'mode'       => $session['mode'],
            'pending'    => true,
        ];
    }

    /**
     * 停止语音对讲（发送 BYE）
     *
     * @param string $deviceId 设备ID
     * @param string $channelId 通道ID
     * @param string $dialogId SIP Dialog ID
     * @return array
     */
    public function stopVoiceTalk(string $deviceId, string $channelId, string $dialogId) : array
    {
        $this->sendCommand($deviceId, 'voice_bye', [
            'channel_id' => $channelId,
            'dialog_id'  => $dialogId,
        ], false);

        return [
            'success'    => true,
            'device_id'  => $deviceId,
            'channel_id' => $channelId,
        ];
    }

    /**
     * GB28181-2022: 设备升级
     *
     * @param string $deviceId 设备ID
     * @param string $manufacturer 制造商
     * @param string $firmware 固件版本
     * @return array 返回命令信息 (注意: 这里返回数组以便调用方获取 session_id 等信息)
     */
    public function deviceUpgrade(string $deviceId, string $manufacturer, string $firmware) : array
    {
        $sessionId = strtoupper(md5(uniqid() . microtime(true)));
        $sn = rand(1, 99999999);

        $success = $this->sendCommand($deviceId, 'device_upgrade', [
            'manufacturer' => $manufacturer,
            'firmware'     => $firmware,
            'session_id'   => $sessionId,
            'sn'           => $sn,
        ]);

        return [
            'success'    => $success,
            'session_id' => $sessionId,
            'sn'         => $sn,
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
    public function snapshot(string $deviceId, string $channelId, string $imageFormat = 'JPEG') : array
    {
        $sessionId = strtoupper(md5(uniqid() . microtime(true)));
        $sn = rand(1, 99999999);

        $success = $this->sendCommand($deviceId, 'snapshot', [
            'channel_id'   => $channelId,
            'session_id'   => $sessionId,
            'image_format' => $imageFormat,
            'sn'           => $sn,
        ]);

        return [
            'success'      => $success,
            'session_id'   => $sessionId,
            'sn'           => $sn,
            'image_format' => $imageFormat,
        ];
    }

    /**
     * 订阅目录变更
     *
     * @param string $deviceId 设备ID
     * @param array $params 参数
     *   - expires: 订阅有效期（秒），默认3600
     * @return array 返回命令信息 (不等待网关响应，异步处理)
     */
    public function subscribeCatalog(string $deviceId, array $params = []) : array
    {
        $expires = $params['expires'] ?? 3600;

        $this->sendCommand($deviceId, 'subscribe_catalog', [
            'expires' => $expires,
        ], false); // 不等待响应

        return [
            'success'    => true,
            'device_id'  => $deviceId,
            'event_type' => 'Catalog',
            'expires'    => $expires,
            'pending'    => true, // 标记为异步处理中
        ];
    }

    /**
     * 订阅报警
     *
     * @param string $deviceId 设备ID
     * @param array $params 参数
     *   - expires: 订阅有效期（秒），默认3600
     *   - start_priority: 报警最低优先级（1-4) 0=全部
     *   - end_priority: 报警最高优先级（1-4） 0=全部
     *   - alarm_method: 报警方式（1=电话,2=短信,3=邮件,4=APP,5=客户端），0=全部，可选
     *   - start_alarm_time: 报警开始时间，可选,iso
     *   - end_alarm_time: 报警结束时间，可选,iso
     * @return array 返回命令信息 (不等待网关响应，异步处理)
     */
    public function subscribeAlarm(string $deviceId, array $params = []) : array
    {
        $expires = $params['expires'] ?? 3600;
        $startPriority = $params['start_priority'] ?? null;
        $endPriority = $params['end_priority'] ?? null;
        $alarmMethod = $params['alarm_method'] ?? null;
        $alarmType = $params['alarm_type'] ?? null; // 🔑 新增 AlarmType 处理
        $startAlarmTime = $params['start_alarm_time'] ?? null;
        $endAlarmTime = $params['end_alarm_time'] ?? null;

        // 校验 alarm_method 格式（支持单值或 / 分隔组合）
        if ($alarmMethod !== null) {
            if (!preg_match('/^\d+(\/\d+)*$/', $alarmMethod)) {
                throw new \InvalidArgumentException(
                    "Invalid alarm_method format. Expected single value (e.g., '5') or slash-separated values (e.g., '1/2/5')"
                );
            }
        }

        // 关键校验：alarm_method=5/6 时必须提供 alarm_type
        if ($alarmMethod !== null && $alarmType === null) {
            $methods = explode('/', $alarmMethod);
            foreach ($methods as $method) {
                if (in_array((int)$method, [5, 6], true)) {
                    throw new \InvalidArgumentException(
                        "alarm_type is required when alarm_method includes 5 (video analysis) or 6 (storage fault)"
                    );
                }
            }
        }

        // 关键校验：alarm_type 与 alarm_method 的取值范围匹配
        if ($alarmType !== null && $alarmMethod !== null) {
            $methods = explode('/', $alarmMethod);
            $validRanges = [
                2 => [1, 5],    // 设备报警：1-5
                5 => [1, 13],   // 智能分析：1-13
                6 => [1, 2],    // 存储故障：1-2
            ];

            $isValid = false;
            foreach ($methods as $method) {
                $method = (int)$method;
                if (isset($validRanges[$method])) {
                    [$min, $max] = $validRanges[$method];
                    if ($alarmType >= $min && $alarmType <= $max) {
                        $isValid = true;
                        break;
                    }
                }
            }

            if (!$isValid) {
                throw new \InvalidArgumentException(
                    sprintf("alarm_type=%d is invalid for alarm_method=%s", $alarmType, $alarmMethod)
                );
            }
        }

        $cmdParams = [
            'expires' => $expires,
        ];

        if ($alarmMethod !== null) {
            $cmdParams['alarm_method'] = $alarmMethod;
        }

        if ($alarmType !== null) { //必须显式传递
            $cmdParams['alarm_type'] = $alarmType;
        }

        if ($startPriority !== null) {
            $cmdParams['start_priority'] = $startPriority;
        }

        if ($endPriority !== null) {
            $cmdParams['end_priority'] = $endPriority;
        }

        if ($startAlarmTime !== null) {
            $cmdParams['start_alarm_time'] = $startAlarmTime;
        }

        if ($endAlarmTime !== null) {
            $cmdParams['end_alarm_time'] = $endAlarmTime;
        }

        $this->sendCommand($deviceId, 'subscribe_alarm', $cmdParams);

        return [
            'success'    => true,
            'device_id'  => $deviceId,
            'event_type' => 'Alarm',
            'expires'    => $expires,
            'pending'    => true,
        ];
    }

    /**
     * 订阅移动位置
     *
     * @param string $deviceId 设备ID
     * @param array $params 参数
     *   - expires: 订阅有效期（秒），默认3600
     *   - interval: 位置上报间隔（秒），默认5
     * @return array 返回命令信息 (不等待网关响应，异步处理)
     */
    public function subscribeMobilePosition(string $deviceId, array $params = []) : array
    {
        $expires = $params['expires'] ?? 3600;
        $interval = $params['interval'] ?? 5;

        $this->sendCommand($deviceId, 'subscribe_mobile_position', [
            'expires'  => $expires,
            'interval' => $interval,
        ], false); // 不等待响应

        return [
            'success'    => true,
            'device_id'  => $deviceId,
            'event_type' => 'MobilePosition',
            'expires'    => $expires,
            'interval'   => $interval,
            'pending'    => true,
        ];
    }

    /**
     * 取消目录订阅
     *
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function unsubscribeCatalog(string $deviceId) : bool
    {
        return $this->sendCommand($deviceId, 'unsubscribe_catalog');
    }

    /**
     * 取消报警订阅
     *
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function unsubscribeAlarm(string $deviceId) : bool
    {
        return $this->sendCommand($deviceId, 'unsubscribe_alarm');
    }

    /**
     * 取消移动位置订阅
     *
     * @param string $deviceId 设备ID
     * @return bool
     */
    public function unsubscribeMobilePosition(string $deviceId) : bool
    {
        return $this->sendCommand($deviceId, 'unsubscribe_mobile_position');
    }

    /**
     * 刷新订阅(续期)
     *
     * @param int|string $dialogId Dialog ID (从订阅时返回，数据库存储为string，但实际是int)
     * @param string $eventType 事件类型 (Catalog/Alarm/MobilePosition)
     * @param int $expires 新的有效期（秒），默认3600
     * @return array 返回刷新结果 (不等待网关响应，异步处理)
     */
    public function refreshSubscribe(int|string $dialogId, string $eventType, int $expires = 3600) : array
    {
        // dialog_id 必须是有效的整数
        $dialogIdInt = (int)$dialogId;
        if ($dialogIdInt <= 0) {
            return [
                'success'    => false,
                'dialog_id'  => $dialogId,
                'event_type' => $eventType,
                'error'      => 'Invalid dialog_id: must be a positive integer',
            ];
        }

        $this->sendCommand('_refresh_', 'refresh_subscribe', [
            'dialog_id'  => $dialogIdInt,
            'event_type' => $eventType,
            'expires'    => $expires,
        ], false); // 不等待响应

        return [
            'success'    => true,
            'dialog_id'  => $dialogIdInt,
            'event_type' => $eventType,
            'expires'    => $expires,
            'pending'    => true,
        ];
    }

    /**
     * 远程重启
     */
    public function teleBoot(string $deviceId, string $channelId) : bool
    {
        return $this->sendCommand($deviceId, 'tele_boot', [
            'channel_id' => $channelId,
        ]);
    }

    /**
     * 录像控制
     * @param string $action Record / StopRecord
     */
    public function recordControl(string $deviceId, string $channelId, string $action) : bool
    {
        return $this->sendCommand($deviceId, 'record_cmd', [
            'channel_id' => $channelId,
            'action'     => $action,
        ]);
    }

    /**
     * 布防/撤防
     * @param string $action SetGuard / ResetGuard
     */
    public function guardControl(string $deviceId, string $channelId, string $action) : bool
    {
        return $this->sendCommand($deviceId, 'guard_cmd', [
            'channel_id' => $channelId,
            'action'     => $action,
        ]);
    }

    /**
     * 报警复位
     */
    public function alarmReset(string $deviceId, string $channelId, ?int $alarmMethod = null, ?int $alarmType = null) : bool
    {
        $params = ['channel_id' => $channelId];
        if ($alarmMethod !== null) {
            $params['alarm_method'] = $alarmMethod;
        }
        if ($alarmType !== null) {
            $params['alarm_type'] = $alarmType;
        }
        return $this->sendCommand($deviceId, 'alarm_reset', $params);
    }

    /**
     * 强制关键帧
     */
    public function iFrameCmd(string $deviceId, string $channelId) : bool
    {
        return $this->sendCommand($deviceId, 'iframe_cmd', [
            'channel_id' => $channelId,
        ]);
    }

    /**
     * 看守位控制
     */
    public function homePosition(string $deviceId, string $channelId, bool $enabled, int $resetTime = 0, int $presetIndex = 1) : bool
    {
        return $this->sendCommand($deviceId, 'home_position', [
            'channel_id'   => $channelId,
            'enabled'      => $enabled ? 1 : 0,
            'reset_time'   => $resetTime,
            'preset_index' => $presetIndex,
        ]);
    }

    /**
     * 拖拽变倍
     * @param string $type in / out
     */
    public function dragZoom(string $deviceId, string $channelId, string $type, array $params) : bool
    {
        return $this->sendCommand($deviceId, 'drag_zoom', array_merge([
            'channel_id' => $channelId,
            'type'       => $type,
        ], $params));
    }

    /**
     * 设备基础配置
     */
    public function deviceConfig(string $deviceId, string $channelId, array $params) : bool
    {
        return $this->sendCommand($deviceId, 'device_config', array_merge([
            'channel_id' => $channelId,
        ], $params));
    }

    /**
     * 自动扫描控制
     * @param string $action scan_start/scan_stop/scan_set_left/scan_set_right/scan_set_speed
     */
    public function scanControl(string $deviceId, string $channelId, string $action, int $groupId = 0, int $speed = 0) : bool
    {
        return $this->sendCommand($deviceId, $action, [
            'channel_id' => $channelId,
            'group_id'   => $groupId,
            'speed'      => $speed,
        ]);
    }

    /**
     * 雨刷控制
     */
    public function wiperControl(string $deviceId, string $channelId, bool $on) : bool
    {
        return $this->sendCommand($deviceId, $on ? 'wiper_on' : 'wiper_off', [
            'channel_id' => $channelId,
        ]);
    }

    /**
     * 辅助开关控制
     */
    public function auxControl(string $deviceId, string $channelId, int $switchId, bool $on) : bool
    {
        return $this->sendCommand($deviceId, $on ? 'aux_on' : 'aux_off', [
            'channel_id' => $channelId,
            'switch_id'  => $switchId,
        ]);
    }

    /**
     * 巡航控制
     */
    public function cruiseControl(string $deviceId, string $channelId, string $action, int $groupId, int $param = 0) : bool
    {
        return $this->sendCommand($deviceId, 'cruise_' . $action, [
            'channel_id' => $channelId,
            'group_id'   => $groupId,
            'preset_id'  => $param,
            'speed'      => $param,
            'duration'   => $param,
        ]);
    }

    /**
     * 设备预置位查询
     */
    public function presetQuery(string $deviceId, string $channelId) : bool
    {
        return $this->sendCommand($deviceId, 'preset_query', [
            'channel_id' => $channelId,
        ]);
    }

    /**
     * 设备配置查询
     */
    public function configDownload(string $deviceId, string $channelId, string $configType = 'BasicParam') : bool
    {
        return $this->sendCommand($deviceId, 'config_download', [
            'channel_id'  => $channelId,
            'config_type' => $configType,
        ]);
    }

    private function checkGatewayIsRunning() : bool
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


    private function checkPortProtocol($port) : array
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

    private function isUdpOpen($host, $port) : bool
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


    private function isTcpOpen($host, $port, $timeout = 1) : bool
    {
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);
            return true;
        }
        return false;
    }

}