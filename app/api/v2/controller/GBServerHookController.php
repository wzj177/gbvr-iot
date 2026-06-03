<?php

namespace app\api\v2\controller;

use app\api\BaseController;
use CoreW\Business\Alarm\Service\AlarmEventService;
use CoreW\Business\Devices\Enums\ChannelStreamStatus;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Enums\StreamSessionStatus;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Service\PlaybackRecordService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Sdk\PSipGateway\Gb28181Client;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Log;
use support\Redis;
use support\Request;

/**
 * GB28181 信令网关 Hook 接收器
 *
 * 接收信令网关推送的事件：
 * - register: 设备注册
 * - update_heartbeat: 心跳更新
 * - save_catalog: 设备目录
 * - media_ready: 媒体流就绪（收到设备200 OK）
 * - device_status: 设备状态变化
 * - alarm: 报警信息
 */
class GBServerHookController extends BaseController
{

    public function index(Request $request) : \support\Response
    {
        $scene = $request->post('scene');
        $body = $request->post('body', []);

        // 高频场景跳过日志写磁盘，避免 I/O 阻塞事件循环
        // 生产环境心跳/sip_xml 量级大，写日志意义不大
        $skipLogScenes = ['sip_xml', 'update_heartbeat'];
        if (!in_array($scene, $skipLogScenes, true)) {
            Log::channel('sip')->info('GBServer Hook Received', [
                'scene' => $scene,
                'body'  => $body,
            ]);
        }

        try {
            // 需要返回数据的 scene 返回 array，其他返回 null
            $result = match ($scene) {
                'sip_xml' => $this->handleSipXml($body),
                'register' => $this->handleRegister($body),
                'device_unregister' => $this->handleUnRegister($body),
                'device_expired' => $this->handleExpired($body),
                'update_heartbeat' => $this->handleHeartbeat($body),
                'device_catalog' => $this->handleCatalog($body),
                'device_info' => $this->handleDeviceInfo($body),
                'media_ready' => $this->handleMediaReady($body),
                'voice_established' => $this->handleVoiceEstablished($body),
                'session_bye' => $this->handleSessionBye($body),
                'device_status' => $this->handleDeviceStatus($body),
                'record_info' => $this->handleRecordInfo($body),
                'alarm' => $this->handleAlarm($body),
                'command_confirmed' => $this->handleCommandConfirmed($body),
                'catalog_update' => $this->handleCatalogUpdate($body),
                'alarm_event' => $this->handleAlarmEvent($body),
                'position_update' => $this->handlePositionUpdate($body),
                'mobile_position_subscribe' => $this->handleMobilePositionSubscribe($body),
                'mobile_position_unsubscribe' => $this->handleMobilePositionUnsubscribe($body),
                'gateway_cmd_after' => $this->handleGatewayCmdAfter($body),
                'broadcast_setup_rtp' => $this->handleBroadcastSetupRtp($body),
                'start_send_rtp' => $this->handleStartSendRtp($body),
                'broadcast_stop' => $this->handleBroadcastStop($body),
                'preset_query_result' => $this->handlePresetQueryResult($body),
                'config_download_result' => $this->handleConfigDownloadResult($body),
                'mobile_position_report' => $this->handleMobilePositionReport($body),
                default => Log::channel('sip')->warning('Unknown hook scene', ['scene' => $scene]),
            };

            return is_array($result)
                ? $this->createSuccessJsonResponse($result)
                : $this->createSuccessJsonResponse();

        } catch (\Exception $e) {
            Log::channel('sip')->error('Hook handler exception', [
                'scene'     => $scene,
                'exception' => $e->getMessage(),
                'trace'     => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    private function handleSipXml(array $body) : void
    {
        // SIP XML 日志默认关闭，通过 .env SIP_XML_LOG_ENABLED=true 开启
        // 生产环境建议关闭，避免每个 hook 请求同步写磁盘阻塞事件循环
        if (!getenv('SIP_XML_LOG_ENABLED')) {
            return;
        }

        $deviceId = $body['device_id'] ?? '';
        if (!$deviceId) {
            return;
        }
        $xml = $body['xml'] ?? '';

        if (!$xml) {
            return;
        }

        $path = runtime_path('sip/xml/');
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        $filename = $path . $deviceId . '-' . date('Ymd') . '.log';

        file_put_contents($filename, $xml, FILE_APPEND);
    }

    /**
     * 处理网关命令执行完成将接口回推到api
     * @param array $body
     * @return void
     */
    private function handleGatewayCmdAfter(array $body) : void
    {
        Log::channel('sip')->info('Gateway command after', $body);
    }

    /**
     * 处理设备返回的预置位查询结果
     *
     * 设备返回 XML:
     * <Response><CmdType>PresetQuery</CmdType><PresetList>...</PresetList></Response>
     */
    private function handlePresetQueryResult(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        $presetList = $body['preset_list'] ?? [];
        $num = $body['num'] ?? count($presetList);

        Log::channel('sip')->info('Preset query result', [
            'device_id' => $deviceId,
            'num'       => $num,
            'presets'   => $presetList,
        ]);

        if ($deviceId && !empty($presetList)) {
            try {
                $this->getDeviceService()->syncPresetsFromDevice($deviceId, $presetList);
            } catch (\Exception $e) {
                Log::channel('sip')->error('Failed to sync presets from device', [
                    'device_id' => $deviceId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 处理设备返回的配置查询结果
     * @deprecated
     * 设备返回 XML:
     * <Response><CmdType>ConfigDownload</CmdType><BasicParam>...</BasicParam></Response>
     */
    private function handleConfigDownloadResult(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        $basicParam = $body['basic_param'] ?? [];
        $result = $body['result'] ?? '';

        Log::channel('sip')->info('Config download result', [
            'device_id'   => $deviceId,
            'result'      => $result,
            'basic_param' => $basicParam,
        ]);

        // 将配置信息更新到数据库（如设备名称、心跳参数等）
        if ($deviceId && !empty($basicParam) && $result === 'OK') {
            try {
                $updateFields = [];
                if (!empty($basicParam['Name'])) {
                    $updateFields['name'] = $basicParam['Name'];
                }
                if (!empty($basicParam['HeartBeatInterval'])) {
                    $updateFields['heartbeat_interval'] = (int)$basicParam['HeartBeatInterval'];
                }
                if (!empty($basicParam['HeartBeatCount'])) {
                    $updateFields['heartbeat_count'] = (int)$basicParam['HeartBeatCount'];
                }
                if (!empty($basicParam['Expiration'])) {
                    $updateFields['expiration'] = (int)$basicParam['Expiration'];
                }
                if (!empty($updateFields)) {
                    $this->getDeviceService()->updateDeviceConfig($deviceId, $updateFields);
                }
            } catch (\Exception $e) {
                Log::channel('sip')->error('Failed to update device config', [
                    'device_id' => $deviceId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * 处理设备注册
     */
    private function handleRegister(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        if (!$deviceId) {
            Log::channel('sip')->warning('Register without device_id', ['body' => $body]);
            return;
        }

        try {
            $device = $this->getDeviceService()->handleDeviceRegister($deviceId, $body);

            // Bind device to gateway if gateway_id is present in the hook body
            $gatewayId = $body['gateway_id'] ?? null;
            if ($gatewayId) {
                $this->getDeviceService()->bindDeviceToGateway($deviceId, $gatewayId);
            }

            // 发送设备信息查询请求，在handleDeviceInfo收到设备信息后，处理
            $this->getGb28181Service()->queryDeviceInfo($deviceId);

            // 兜底：通过 Redis 队列发送 catalog 查询
            // Gateway 端 handleRegister 也会直接发 SIP queryCatalog，但高并发下可能发送失败且无重试
            // 这里通过队列再发一次，确保 catalog 查询不丢失
            $this->getGb28181Service()->queryCatalog($deviceId);

            Log::channel('sip')->info('Device registered', [
                'device_id'  => $deviceId,
                'status'     => $device['status'] ?? 'unknown',
                'gateway_id' => $gatewayId,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Register failed', [
                'device_id' => $deviceId,
                'error'     => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 处理设备注销 (主动注销)
     */
    private function handleUnRegister(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        if (!$deviceId) {
            return;
        }

        try {
            // 更新设备状态为已注销
            $this->getDeviceService()->updateDeviceStatus($deviceId, DeviceStatusEnum::UNREGISTERED->value);

            Log::channel('sip')->info('Device unregistered', [
                'device_id' => $deviceId,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Unregister failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理设备心跳超时
     */
    private function handleExpired(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        if (!$deviceId) {
            return;
        }

        try {
            // 更新设备状态为超时
            $this->getDeviceService()->updateDeviceStatus($deviceId, DeviceStatusEnum::EXPIRED->value);

            Log::channel('sip')->warning('Device heartbeat expired', [
                'device_id'      => $deviceId,
                'last_heartbeat' => $body['last_heartbeat'] ?? 0,
                'timeout'        => $body['timeout'] ?? 0,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Expired handler failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * @deprecated
     * 处理设备长期注销，这里仅更新设备状态为已注销
     */
    private function handleOffline(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        if (!$deviceId) {
            return;
        }

        try {
            // 更新设备状态为已注销
            $this->getDeviceService()->updateDeviceStatus($deviceId, DeviceStatusEnum::UNREGISTERED->value);

            Log::channel('sip')->info('Device offline (cleaned)', [
                'device_id'      => $deviceId,
                'registered_at'  => $body['registered_at'] ?? 0,
                'last_heartbeat' => $body['last_heartbeat'] ?? 0,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Offline handler failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理心跳更新
     */
    private function handleHeartbeat(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        if (!$deviceId) {
            return;
        }

        try {
            $this->getDeviceService()->updateDeviceHeartbeat($deviceId);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Heartbeat update failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理设备目录
     */
    private function handleCatalog(array $body) : void
    {
        Log::channel('sip')->debug('Catalog received', $body);
        $deviceId = $body['device_id'] ?? '';
        $devices = $body['devices'] ?? [];

        if (!$deviceId || empty($devices)) {
            Log::channel('sip')->warning('Catalog missing data', [
                'device_id' => $deviceId,
                'count'     => count($devices),
            ]);
            return;
        }

        try {
            $count = $this->getDeviceService()->batchUpdateOrCreateChannels($deviceId, $devices);

            Log::channel('sip')->info('Device catalog saved', [
                'device_id' => $deviceId,
                'count'     => $count,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Catalog save failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理媒体流就绪（收到设备200 OK，包含设备SSRC）
     */
    private function handleMediaReady(array $body) : void
    {
        $callId = $body['call_id'] ?? 0;
        $deviceSsrc = $body['device_ssrc'] ?? '';
        $sdp = $body['sdp'] ?? [];
        $sessionName = $sdp['session_name'] ?? '';

        if (!$callId) {
            Log::channel('sip')->warning('Media ready without call_id');
            return;
        }

        try {
            // 如果是 Download 类型，更新录像任务状态和 INVITE 成功时间
            if (strtolower($sessionName) === 'download') {
                $this->getRecordTaskService()->updateTaskStatusWhenMediaReady((string)$deviceSsrc, time());
            }

            // 查找会话
            $session = $this->getDeviceService()->getSessionBySsrc((string)$deviceSsrc);
            if (!$session) {
                Log::channel('sip')->warning("{$deviceSsrc} Session not found");
                return;
            }

            // 更新会话状态和设备 SSRC
            $updateData = [
                'status'      => StreamSessionStatus::Active->value,
                'device_ip'   => !empty($body['device_ip']) ? $body['device_ip'] : $sdp['origin']['addr'] ?? null,
                'device_port' => $body['device_port'] ?? 0,
                'call_id'     => $callId,
                'dialog_id'   => $body['dialog_id'] ?? -1,
                'sdp'         => isset($body['sdp']) ? serialize($sdp) : null,
            ];

            $this->getDeviceService()->updateChannelByMainId($session['stream_id'], [
                'stream_status' => ChannelStreamStatus::PUSHING->value,
            ]);
            $this->getDeviceService()->updateSessionBySSRC($deviceSsrc, $updateData);

        } catch (\Exception $e) {
            Log::channel('sip')->error('Media ready handler failed', [
                'call_id' => $callId,
                'error'   => $e->getMessage(),
            ]);
        }
    }


    /**
     * 处理语音对讲会话已建立（INVITE 200 OK 回调）
     *
     * 触发时机：
     * 当信令网关收到设备对 voice talk INVITE 的 200 OK 响应时，
     * GB28181Handler::handleVoiceTalkEstablished() 通过 postTask('voice_established', ...)
     * 将回调数据推送到此处。
     *
     * 数据来源：
     * - session_id: 来自 CommandDispatcher::activeSessions（在 handleVoiceInvite 时由 API 层传入）
     * - call_id/dialog_id: 来自 eXosip 的 200 OK 事件
     * - device_ip/device_port: 来自设备 200 OK 的 SDP
     *
     * 处理流程：
     * 1. 验证必需参数
     * 2. 调用 VoiceTalkService::onSipResponseOk() 更新会话状态为 CONNECTED
     * 3. VoiceTalkService 内部保存 sendRtpInfo 并更新数据库
     */
    private function handleVoiceEstablished(array $body) : void
    {
        $sessionId = $body['session_id'] ?? '';
        $deviceId = $body['device_id'] ?? '';
        $channelId = $body['channel_id'] ?? '';
        $callId = $body['call_id'] ?? 0;
        $dialogId = $body['dialog_id'] ?? -1;

        if (!$sessionId) {
            Log::channel('sip')->warning('Voice established without session_id', ['body' => $body]);
            return;
        }

        Log::channel('sip')->info('Voice talk established', [
            'session_id'  => $sessionId,
            'device_id'   => $deviceId,
            'channel_id'  => $channelId,
            'call_id'     => $callId,
            'dialog_id'   => $dialogId,
            'device_ip'   => $body['device_ip'] ?? null,
            'device_port' => $body['device_port'] ?? null,
        ]);

        try {
            // 调用 VoiceTalkService::onSipResponseOk() 更新会话状态
            $sipResponse = [
                'call_id'     => $callId,
                'dialog_id'   => $dialogId,
                'device_ip'   => $body['device_ip'] ?? null,
                'device_port' => $body['device_port'] ?? null,
                'ssrc'        => $body['ssrc'] ?? null,
                'stream_id'   => $body['stream_id'] ?? null,
                'mode'        => $body['mode'] ?? 'talk',
            ];

            $this->getVoiceTalkService()->onSipResponseOk($sessionId, $sipResponse);

            Log::channel('sip')->info('Voice talk session updated to CONNECTED', [
                'session_id' => $sessionId,
            ]);

        } catch (\Exception $e) {
            Log::channel('sip')->error('Voice established handler failed', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 处理会话结束（BYE）
     */
    private function handleSessionBye(array $body) : void
    {
        $callId = $body['call_id'] ?? 0;
        $deviceId = $body['device_id'] ?? '';

        Log::channel('sip')->info('Session bye', [
            'call_id'   => $callId,
            'device_id' => $deviceId,
        ]);

        if (!$callId) {
            return;
        }

        try {
            // 1. 先查找普通视频会话
            $session = $this->getDeviceService()->getSessionByCallId((int)$callId);
            if ($session) {
                // 处理普通视频会话的 BYE
                $streamId = $session['stream_id'] ?? '';
                $port = $session['rtp_port'] ?? 0;

                // 关闭 ZLM 流
                if ($streamId) {
                    try {
                        $this->getGb28181Service()->closeStream('rtp', $streamId);
                        Log::channel('sip')->info('Stream closed', ['stream_id' => $streamId]);
                    } catch (\Exception $e) {
                        Log::channel('sip')->warning('Close stream failed', [
                            'stream_id' => $streamId,
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }

                // 关闭 RTP 端口
                if ($port > 0) {
                    try {
                        $this->getGb28181Service()->closeRtpServer($streamId);
                        Log::channel('sip')->info('RTP port closed', ['port' => $port]);
                    } catch (\Exception $e) {
                        Log::channel('sip')->warning('Close RTP port failed', [
                            'port'  => $port,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // 删除会话记录
                $this->getDeviceService()->deleteSessionByCallId((int)$callId);

                Log::channel('sip')->info('Video session cleaned up', ['call_id' => $callId]);
                return;
            }

            // 2. 再查找语音对讲会话（设备主动 BYE 结束对讲）
            $voiceSession = $this->getVoiceTalkService()->getSessionByCallId((string)$callId);
            if ($voiceSession) {
                Log::channel('sip')->info('Voice talk session bye from device', [
                    'call_id'    => $callId,
                    'session_id' => $voiceSession['session_id'] ?? null,
                ]);

                // 调用 VoiceTalkService 的统一清理方法
                $this->getVoiceTalkService()->stopVoiceTalkBySession($voiceSession, 'device_bye');

                Log::channel('sip')->info('Voice talk session cleaned up', [
                    'call_id'    => $callId,
                    'session_id' => $voiceSession['session_id'] ?? null,
                ]);
                return;
            }

            // 3. 未找到任何会话
            Log::channel('sip')->warning('Session not found for BYE (neither video nor voice)', [
                'call_id' => $callId,
            ]);

        } catch (\Exception $e) {
            Log::channel('sip')->error('Session bye handler failed', [
                'call_id' => $callId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理设备状态变化
     */
    private function handleDeviceStatus(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        $online = $body['online'] ?? 'OFFLINE';

        if (!$deviceId) {
            return;
        }

        $status = ($online === 'ONLINE') ? DeviceStatusEnum::ONLINE->value : DeviceStatusEnum::UNREGISTERED->value;

        try {
            $this->getDeviceService()->updateDeviceStatus($deviceId, $status);

            Log::channel('sip')->info('Device status changed', [
                'device_id' => $deviceId,
                'status'    => $status,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Device Status update failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function handleRecordInfo(array $body)
    {
        //{"scene":"record_info","body":{"device_id":"34020000001320456626","record_info":{"device_id":"34020000001320456626","sum_num":2,"record_list":[{"DeviceID":"34020000001310000001","Name":"CH1_20260115123217881.mp4","FilePath":"","Address":"","StartTime":"2026-01-15T12:32:17","EndTime":"2026-01-15T12:32:51","Secrecy":"0","Type":"time","RecorderID":""},{"DeviceID":"34020000001310000001","Name":"CH1_20260115130729523.mp4","FilePath":"","Address":"","StartTime":"2026-01-15T13:07:29","EndTime":"2026-01-15T13:20:04","Secrecy":"0","Type":"time","RecorderID":""}],"cmd_type":"RecordInfo"},"timestamp":1768666454}}
        $recordInfo = $body['record_info'] ?? null;
        if (!$recordInfo) {
            return;
        }

        if ($recordInfo['sum_num'] <= 0) {
            Log::channel('sip')->info("设备：{$body['device_id']} 无录像");
            return;
        }
        $recordList = $recordInfo['record_list'] ?? [];
        if (!$recordList) {
            return;
        }

        try {
            $records = [];
            foreach ($recordList as $item) {
                $records[] = [
                    'device_id'   => $body['device_id'],
                    'channel_id'  => $item['DeviceID'],
                    'name'        => $item['Name'],
                    'file_path'   => $item['FilePath'] ?? '',
                    'address'     => $item['Address'] ?? '',
                    'start_time'  => strtotime($item['StartTime']),
                    'end_time'    => strtotime($item['EndTime']),
                    'secrecy'     => (int)($item['Secrecy'] ?? 0),
                    'type'        => $item['Type'] ?? 'time',
                    'recorder_id' => $item['RecorderID'] ?? '',
                ];
            }

            // 使用 savePlaybackRecords 自动去重
            $this->getPlaybackRecordService()->savePlaybackRecords($records);

            Log::channel('sip')->info('Playback records saved', [
                'device_id' => $body['device_id'],
                'count'     => count($records),
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Playback records save failed', [
                'device_id' => $body['device_id'],
                'error'     => $e->getMessage(),
            ]);
        }

        return;
    }

    private function handleDeviceInfo(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        if (!$deviceId) {
            return;
        }

        $info = $body['device_info'] ?? null;
        if (!$info) {
            return;
        }

        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);
        if (!$device) {
            return;
        }

        try {
            $this->getDeviceService()->updateDevice($device['id'], [
                'device_name'  => $info['DeviceName'] ?? $device['name'],
                'manufacturer' => $info['Manufacturer'] ?? $device['manufacturer'],
                'model'        => $info['Model'] ?? $device['model'],
                'firmware'     => $info['Firmware'] ?? $device['firmware'],
                'sum_num'      => !empty($info['Channel']) ? intval($info['Channel']) : $device['sum_num'] ?? 1,
            ]);

            Log::channel('sip')->info('Device Info changed', [
                'device_id' => $deviceId,
                'info'      => $info,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Device Info update failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理报警信息
     */
    private function handleAlarm(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        $priority = $body['priority'] ?? '1';
        $method = $body['method'] ?? '';

        Log::channel('sip')->warning('Device alarm', [
            'device_id' => $deviceId,
            'priority'  => $priority,
            'method'    => $method,
            'data'      => $body['data'] ?? [],
        ]);

        // TODO: 存储报警记录到数据库
        // TODO: 推送报警通知
    }

    /**
     * 处理设备确认收到指令（MESSAGE 200 OK 响应）
     *
     * 重要说明：
     * - 这只是确认"设备收到了指令"，不是"指令执行完成"
     * - 实际的执行结果会通过后续的 MESSAGE 请求返回（带 XML body）
     * - 业务系统可以根据 call_id 或 cseq 关联原始请求
     *
     * 应用场景：
     * - PTZ 控制：记录指令发送成功，更新设备控制日志
     * - 录像控制：标记录像任务已提交
     * - 设备配置：标记配置命令已下发
     */
    private function handleCommandConfirmed(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        $callId = $body['call_id'] ?? 0;
        $cseq = $body['cseq'] ?? 0;
        $statusCode = $body['status_code'] ?? 200;

        if (!$deviceId) {
            Log::channel('sip')->warning('Command confirmed without device_id', ['body' => $body]);
            return;
        }

        Log::channel('sip')->info('Device confirmed command', [
            'device_id'   => $deviceId,
            'call_id'     => $callId,
            'cseq'        => $cseq,
            'status_code' => $statusCode,
        ]);

        try {
            // TODO: 根据业务需求更新数据库
            // 例如：
            // - 更新设备控制日志表：标记指令已送达
            // - 更新 PTZ 操作记录：状态从 "发送中" → "已确认"
            // - 更新录像任务：状态从 "请求中" → "处理中"

            // 示例：使用 DeviceService 更新设备最后操作时间
            $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);
            if ($device) {
                // 可以更新设备的 last_command_time 字段
                // $this->getDeviceService()->updateDevice($device['id'], [
                //     'last_command_time' => time(),
                //     'last_command_status' => 'confirmed'
                // ]);

                Log::channel('sip')->debug('Device command confirmed', [
                    'device_id'   => $deviceId,
                    'device_name' => $device['device_name'] ?? 'unknown',
                ]);
            }

        } catch (\Exception $e) {
            Log::channel('sip')->error('Handle command confirmed failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理广播 RTP 设置（设备 INVITE 到达后由网关回调）
     *
     * WVP 对齐时序：设备收到 Broadcast MESSAGE 后发送 INVITE，
     * 网关解析设备 SDP 后回调此接口，开设 ZLM 端口并返回参数给网关构建 200 OK SDP。
     *
     * 同时检查流是否就绪（isStreamReady），如果流已不存在，
     * 返回 stream_ready=false，网关层将回复 410 Gone。
     *
     * @param array $body 请求体
     * @return array 返回 local_port, media_server_ip, ssrc, tcp_mode, stream_ready
     */
    private function handleBroadcastSetupRtp(array $body) : array
    {
        $sessionId = $body['session_id'] ?? '';
        if (!$sessionId) {
            throw new \InvalidArgumentException('session_id is required');
        }

        Log::channel('sip')->info('Broadcast setup RTP', [
            'session_id'       => $sessionId,
            'device_transport' => $body['device_transport'] ?? null,
            'device_setup'     => $body['device_setup'] ?? null,
        ]);

        $deviceSdpInfo = [
            'device_transport' => $body['device_transport'] ?? 'RTP/AVP',
            'device_setup'     => $body['device_setup'] ?? 'active',
            'device_ip'        => $body['device_ip'] ?? null,
            'device_port'      => $body['device_port'] ?? null,
        ];

        $result = $this->getVoiceTalkService()->setupBroadcastRtp($sessionId, $deviceSdpInfo);

        // === WVP 对齐：检查流是否就绪 ===
        // 在 ZLM 端口开设成功后，检查推流是否仍然存在
        // 如果前端已经停止推流，ZLM 上的流不存在，网关应回复 410 Gone
        $app = $body['app'] ?? 'broadcast';
        $streamId = $body['stream_id'] ?? '';
        $mediaServerId = $body['media_server_id'] ?? '';

        $streamReady = false;
        if ($streamId && $mediaServerId) {
            $streamReady = $this->getVoiceTalkService()->isStreamReady($mediaServerId, $app, $streamId);
        }

        $result['stream_ready'] = $streamReady;

        Log::channel('sip')->info('Broadcast setup RTP result', [
            'session_id'   => $sessionId,
            'local_port'   => $result['local_port'] ?? null,
            'tcp_mode'     => $result['tcp_mode'] ?? null,
            'stream_ready' => $streamReady,
        ]);

        return $result;
    }

    /**
     * 处理开始推流（广播模式 ACK 后或立即推流）
     *
     * WVP 对齐：收到设备 ACK 后（或 broadcastPushAfterAck=false 时立即），
     * 根据传输模式决定推流方式：
     * - TCP 被动模式 (tcpMode=1): startSendRtpPassive 已在 setupBroadcastRtp 完成，
     *   设备 ACK 后会主动连接 ZLM，无需额外操作，仅记录日志
     * - TCP 主动模式 (tcpMode=2): 调用 startSendRtp 让 ZLM 主动连接设备
     * - UDP 模式 (tcpMode=0): 调用 startSendRtp 让 ZLM 向设备 IP:Port 推送 RTP
     *
     * @param array $body 请求体
     * @return array|null
     */
    private function handleStartSendRtp(array $body) : ?array
    {
        $sessionId = $body['session_id'] ?? '';
        $ssrc = $body['ssrc'] ?? '';
        $streamId = $body['stream_id'] ?? '';
        $app = $body['app'] ?? 'broadcast';
        $mediaServerId = $body['media_server_id'] ?? '';
        $tcpMode = (int)($body['tcp_mode'] ?? 0);

        if (!$sessionId || !$streamId || !$mediaServerId) {
            Log::channel('sip')->warning('start_send_rtp missing required fields', ['body' => $body]);
            return null;
        }

        Log::channel('sip')->info('Start send RTP for broadcast', [
            'session_id' => $sessionId,
            'stream_id'  => $streamId,
            'ssrc'       => $ssrc,
            'app'        => $app,
            'tcp_mode'   => $tcpMode,
        ]);

        try {
            // 先检查流是否仍然存在
            $streamReady = $this->getVoiceTalkService()->isStreamReady($mediaServerId, $app, $streamId);
            if (!$streamReady) {
                Log::channel('sip')->warning('Stream not ready when starting send RTP', [
                    'session_id' => $sessionId,
                    'stream_id'  => $streamId,
                ]);

                // 流已不存在，触发清理
                $session = $this->getVoiceTalkService()->getSession($sessionId);
                if ($session) {
                    $this->getVoiceTalkService()->stopVoiceTalkBySession($session, 'stream_gone_on_start_rtp');
                }

                return ['success' => false, 'reason' => 'stream_not_ready'];
            }

            // TCP 被动模式 (tcpMode=1): startSendRtpPassive 已在 setupBroadcastRtp 完成
            // 设备收到 200 OK 后主动连接 ZLM 监听端口，无需额外推流调用
            if ($tcpMode === 1) {
                Log::channel('sip')->info('TCP passive mode: no startSendRtp needed, device will connect to ZLM', [
                    'session_id' => $sessionId,
                ]);
                return ['success' => true, 'reason' => 'tcp_passive_no_action_needed'];
            }

            // UDP 或 TCP 主动模式：需要主动推流到设备
            $session = $this->getVoiceTalkService()->getSession($sessionId);
            if (!$session) {
                Log::channel('sip')->warning('Session not found for start_send_rtp', [
                    'session_id' => $sessionId,
                ]);
                return ['success' => false, 'reason' => 'session_not_found'];
            }

            $deviceIp = $body['device_ip'] ?? null;
            $devicePort = $body['device_port'] ?? null;

            if (!$deviceIp || !$devicePort) {
                Log::channel('sip')->warning('Missing device IP/Port for active push', [
                    'session_id'  => $sessionId,
                    'device_ip'   => $deviceIp,
                    'device_port' => $devicePort,
                ]);
                return ['success' => false, 'reason' => 'missing_device_address'];
            }

            // 获取 ZLM 客户端并调用 startSendRtp
            $gb28181Service = $this->getGb28181Service();
            $zlmClient = $gb28181Service->getZlmClientByServerId($mediaServerId);

            $isUdp = ($tcpMode === 0);
            $localPort = (int)($body['local_port'] ?? 0);

            $result = $zlmClient->startSendRtp(
                '__defaultVhost__',
                $app,
                $streamId,
                $ssrc,
                $deviceIp,
                (string)$devicePort,
                $isUdp,
                $localPort > 0 ? $localPort : null,
                8,       // pt=8 (G.711A / PCMA)
                false,   // use_ps=false (纯音频不需要 PS 封装)
                true,    // only_audio=true
            );

            Log::channel('sip')->info('startSendRtp result', [
                'session_id'    => $sessionId,
                'tcp_mode'      => $tcpMode,
                'is_udp'        => $isUdp,
                'device_target' => "{$deviceIp}:{$devicePort}",
                'result'        => $result,
            ]);

            return ['success' => true, 'result' => $result];

        } catch (\Exception $e) {
            Log::channel('sip')->error('start_send_rtp failed', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return ['success' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * 处理广播停止（流不存在时的清理）
     *
     * 当网关发现流已不存在时（410 Gone），回调此接口进行清理。
     *
     * @param array $body 请求体
     */
    private function handleBroadcastStop(array $body) : void
    {
        $sessionId = $body['session_id'] ?? '';
        $reason = $body['reason'] ?? 'unknown';

        if (!$sessionId) {
            Log::channel('sip')->warning('broadcast_stop without session_id', ['body' => $body]);
            return;
        }

        Log::channel('sip')->info('Broadcast stop requested', [
            'session_id' => $sessionId,
            'reason'     => $reason,
        ]);

        try {
            $session = $this->getVoiceTalkService()->getSession($sessionId);
            if ($session) {
                $this->getVoiceTalkService()->stopVoiceTalkBySession($session, $reason);
                Log::channel('sip')->info('Broadcast session stopped', [
                    'session_id' => $sessionId,
                ]);
            } else {
                Log::channel('sip')->warning('Broadcast session not found for stop', [
                    'session_id' => $sessionId,
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('sip')->error('broadcast_stop failed', [
                'session_id' => $sessionId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return DeviceService
     */
    private function getDeviceService() : DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }


    /**
     * @return Gb28181Service
     */
    private function getGb28181Service() : Gb28181Service
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }

    /**
     * @return PlaybackRecordService
     */
    private function getPlaybackRecordService() : PlaybackRecordService
    {
        return $this->createService('Devices:PlaybackRecordService');
    }

    /**
     * @return AlarmEventService
     */
    private function getAlarmEventService() : AlarmEventService
    {
        return $this->createService('Alarm:AlarmEventService');
    }

    /**
     * @return RecordTaskService
     */
    private function getRecordTaskService() : \CoreW\Business\Record\Service\RecordTaskService
    {
        return $this->createService('Record:RecordTaskService');
    }

    /**
     * @return \CoreW\Business\Devices\Service\VoiceTalkService
     */
    private function getVoiceTalkService() : \CoreW\Business\Devices\Service\VoiceTalkService
    {
        return $this->createService('Devices:VoiceTalkService');
    }

    /**
     * 处理目录变更通知（NOTIFY with Event: Catalog）
     * 设备主动推送目录变更时触发
     */
    private function handleCatalogUpdate(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        $devices = $body['devices'] ?? [];

        if (!$deviceId || empty($devices)) {
            Log::channel('sip')->warning('Catalog update missing data', [
                'device_id' => $deviceId,
                'count'     => count($devices),
            ]);
            return;
        }

        try {
            // 复用 handleCatalog 方法，逻辑完全相同
            $count = $this->getDeviceService()->batchUpdateOrCreateChannels($deviceId, $devices);

            Log::channel('sip')->info('Catalog update handled', [
                'device_id' => $deviceId,
                'count'     => $count,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Catalog update handler failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理报警事件通知（NOTIFY with Event: Alarm）
     * 设备主动推送报警时触发
     */
    private function handleAlarmEvent(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        $alarmData = $body['data'] ?? [];

        if (!$deviceId) {
            Log::channel('sip')->warning('Alarm event without device_id');
            return;
        }

        try {
            // 解析报警数据
            $alarmEvent = [
                'device_id'   => $deviceId,
                'channel_id'  => $alarmData['channel_id'] ?? $deviceId,
                'level'       => $alarmData['priority'] ?? $alarmData['level'] ?? 1,
                'method'      => $alarmData['method'] ?? $alarmData['alarm_method'] ?? 1,
                'type'        => $alarmData['alarm_type'] ?? $alarmData['type'] ?? null,
                'eventtype'   => $alarmData['event_type'] ?? $alarmData['eventtype'] ?? null,
                'description' => $alarmData['description'] ?? $alarmData['alarm_description'] ?? '',
                'longitude'   => $alarmData['longitude'] ?? null,
                'latitude'    => $alarmData['latitude'] ?? null,
                'alarm_time'  => $alarmData['alarm_time'] ?? date('Y-m-d H:i:s.v'),
                'recv_time'   => date('Y-m-d H:i:s.v'),
                'raw_payload' => $body['raw_payload'] ?? json_encode($alarmData, JSON_UNESCAPED_UNICODE),
            ];

            // 调用 AlarmEventService 处理报警事件
            $event = $this->getAlarmEventService()->handleAlarmNotify($alarmEvent);

            Log::channel('sip')->info('Alarm event handled', [
                'event_id'      => $event['id'],
                'device_id'     => $deviceId,
                'channel_id'    => $event['channel_id'],
                'level'         => $event['level'],
                'alarm_plan_id' => $event['alarm_plan_id'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::channel('sip')->error('Alarm event handler failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理位置更新通知（NOTIFY with Event: presence）
     * 移动设备周期性推送位置时触发
     */
    private function handlePositionUpdate(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        $positionData = $body['position'] ?? $body['data'] ?? [];

        if (!$deviceId || empty($positionData)) {
            Log::channel('sip')->warning('Position update missing data', [
                'device_id' => $deviceId,
            ]);
            return;
        }

        try {
            $position = [
                'device_id'   => $deviceId,
                'longitude'   => $positionData['longitude'] ?? 0,
                'latitude'    => $positionData['latitude'] ?? 0,
                'speed'       => $positionData['speed'] ?? 0,
                'direction'   => $positionData['direction'] ?? 0,
                'altitude'    => $positionData['altitude'] ?? 0,
                'record_time' => $positionData['time'] ?? $positionData['alarm_time'] ?? date('Y-m-d H:i:s'),
            ];

            // TODO: 保存位置信息到数据库
            // $this->getDevicePositionService()->savePosition($position);

            Log::channel('sip')->debug('Position update received', [
                'device_id' => $deviceId,
                'longitude' => $position['longitude'],
                'latitude'  => $position['latitude'],
            ]);

        } catch (\Exception $e) {
            Log::channel('sip')->error('Position update handler failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理移动位置订阅确认
     * 订阅成功后网关推送确认
     */
    private function handleMobilePositionSubscribe(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        $expires = $body['expires'] ?? 0;
        $interval = $body['interval'] ?? 5;

        Log::channel('sip')->info('Mobile position subscribed', [
            'device_id' => $deviceId,
            'expires'   => $expires,
            'interval'  => $interval,
        ]);

        // TODO: 更新订阅配置状态
        // $this->getSubscribeService()->markSubscriptionActive($deviceId, 'mobile_position');
    }

    /**
     * 处理移动位置取消订阅确认
     */
    private function handleMobilePositionUnsubscribe(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';

        Log::channel('sip')->info('Mobile position unsubscribed', [
            'device_id' => $deviceId,
        ]);

        // TODO: 更新订阅配置状态
        // $this->getSubscribeService()->markSubscriptionCancelled($deviceId, 'mobile_position');
    }

    /**
     * 处理移动位置上报（mobile_position_report）
     * Gateway收到设备的MobilePosition NOTIFY后投递的任务
     */
    private function handleMobilePositionReport(array $body) : void
    {
        $deviceId = $body['device_id'] ?? '';
        $data = $body['data'] ?? [];

        if (!$deviceId || empty($data)) {
            Log::channel('sip')->warning('Mobile position report missing data', [
                'device_id' => $deviceId,
            ]);
            return;
        }

        try {
            // 构建位置数据
            $positionData = [
                'device_id' => $deviceId,
                'cmd_type'  => $data['cmd_type'] ?? 'MobilePosition',
                'time'      => $data['time'] ?? date('Y-m-d H:i:s'),
                'longitude' => $data['longitude'] ?? 0,
                'latitude'  => $data['latitude'] ?? 0,
                'speed'     => $data['speed'] ?? 0,
                'direction' => $data['direction'] ?? 0,
                'altitude'  => $data['altitude'] ?? 0,
                'recv_time' => date('Y-m-d H:i:s'),
                'raw_data'  => json_encode($data, JSON_UNESCAPED_UNICODE),
            ];

            // 保存位置信息到历史表
            $this->getDevicePositionService()->savePosition($positionData);

            // 同时更新设备表的lat/lng字段（移动设备的当前位置）
            $this->getDeviceService()->updateDevicePosition(
                $deviceId,
                (float)$positionData['longitude'],
                (float)$positionData['latitude']
            );

            Log::channel('sip')->debug('Mobile position saved', [
                'device_id' => $deviceId,
                'longitude' => $positionData['longitude'],
                'latitude'  => $positionData['latitude'],
                'time'      => $positionData['time'],
            ]);

        } catch (\Exception $e) {
            Log::channel('sip')->error('Mobile position report handler failed', [
                'device_id' => $deviceId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * @return \CoreW\Business\Devices\Service\DevicePositionService
     */
    private function getDevicePositionService() : \CoreW\Business\Devices\Service\DevicePositionService
    {
        return $this->createService('Devices:DevicePositionService');
    }
}