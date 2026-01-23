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

    public function index(Request $request): \support\Response
    {
        $scene = $request->post('scene');
        $body = $request->post('body', []);

        if ($scene !== 'sip_xml') {
            Log::channel('sip')->info('GBServer Hook Received', [
                'scene' => $scene,
                'body' => $body,
            ]);
        }

        try {
            match ($scene) {
                'sip_xml' => $this->handleSipXml($body),
                'register' => $this->handleRegister($body),
                'device_unregister' => $this->handleUnRegister($body),
                'device_expired' => $this->handleExpired($body),
                'device_offline' => $this->handleOffline($body),
                'update_heartbeat' => $this->handleHeartbeat($body),
                'device_catalog' => $this->handleCatalog($body),
                'device_info' => $this->handleDeviceInfo($body),
                'media_ready' => $this->handleMediaReady($body),
                'voice_invite' => $this->handleVoiceInvite($body),
                'session_bye' => $this->handleSessionBye($body),
                'device_status' => $this->handleDeviceStatus($body),
                'record_info' => $this->handleRecordInfo($body),
                'alarm' => $this->handleAlarm($body),
                'command_confirmed' => $this->handleCommandConfirmed($body),  // 设备确认收到指令
                'catalog_update' => $this->handleCatalogUpdate($body), // 目录变更通知
                'alarm_event' => $this->handleAlarmEvent($body), // 报警事件通知
                'position_update' => $this->handlePositionUpdate($body), // 位置更新通知
                'mobile_position_subscribe' => $this->handleMobilePositionSubscribe($body), // 移动位置订阅确认
                'mobile_position_unsubscribe' => $this->handleMobilePositionUnsubscribe($body), // 移动位置取消订阅
                'gateway_cmd_after' => $this->handleGatewayCmdAfter($body),
                default => Log::channel('sip')->warning('Unknown hook scene', ['scene' => $scene]),
            };

            return $this->createSuccessJsonResponse();

        } catch (\Exception $e) {
            Log::channel('sip')->error('Hook handler exception', [
                'scene' => $scene,
                'exception' => $e->getMessage(),
                'trace' => $e->getMessage(),
            ]);

            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    private function handleSipXml(array $body): void
    {
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
    private function handleGatewayCmdAfter(array $body): void
    {
        Log::channel('sip')->info('Gateway command after', $body);
    }

    /**
     * 处理设备注册
     */
    private function handleRegister(array $body): void
    {
        $deviceId = $body['device_id'] ?? '';
        if (!$deviceId) {
            Log::channel('sip')->warning('Register without device_id', ['body' => $body]);
            return;
        }

        try {
            $device = $this->getDeviceService()->handleDeviceRegister($deviceId, $body);

            // 发送设备信息查询请求，在handleDeviceInfo收到设备信息后，处理
            $this->getGb28181Service()->queryDeviceInfo($deviceId);

            Log::channel('sip')->info('Device registered', [
                'device_id' => $deviceId,
                'status' => $device['status'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Register failed', [
                'device_id' => $deviceId,
                'error' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * 处理设备注销 (主动注销)
     */
    private function handleUnRegister(array $body): void
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
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理设备心跳超时
     */
    private function handleExpired(array $body): void
    {
        $deviceId = $body['device_id'] ?? '';
        if (!$deviceId) {
            return;
        }

        try {
            // 更新设备状态为超时
            $this->getDeviceService()->updateDeviceStatus($deviceId, DeviceStatusEnum::EXPIRED->value);

            Log::channel('sip')->warning('Device heartbeat expired', [
                'device_id' => $deviceId,
                'last_heartbeat' => $body['last_heartbeat'] ?? 0,
                'timeout' => $body['timeout'] ?? 0,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Expired handler failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理设备长期注销，这里仅更新设备状态为已注销
     */
    private function handleOffline(array $body): void
    {
        $deviceId = $body['device_id'] ?? '';
        if (!$deviceId) {
            return;
        }

        try {
            // 更新设备状态为已注销
            $this->getDeviceService()->updateDeviceStatus($deviceId, DeviceStatusEnum::UNREGISTERED->value);

            Log::channel('sip')->info('Device offline (cleaned)', [
                'device_id' => $deviceId,
                'registered_at' => $body['registered_at'] ?? 0,
                'last_heartbeat' => $body['last_heartbeat'] ?? 0,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Offline handler failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理心跳更新
     */
    private function handleHeartbeat(array $body): void
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
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理设备目录
     */
    private function handleCatalog(array $body): void
    {
        Log::channel('sip')->debug('Catalog received', $body);
        $deviceId = $body['device_id'] ?? '';
        $devices = $body['devices'] ?? [];

        if (!$deviceId || empty($devices)) {
            Log::channel('sip')->warning('Catalog missing data', [
                'device_id' => $deviceId,
                'count' => count($devices),
            ]);
            return;
        }

        try {
            $count = $this->getDeviceService()->batchUpdateOrCreateChannels($deviceId, $devices);

            Log::channel('sip')->info('Device catalog saved', [
                'device_id' => $deviceId,
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Catalog save failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理媒体流就绪（收到设备200 OK，包含设备SSRC）
     */
    private function handleMediaReady(array $body): void
    {
        $callId = $body['call_id'] ?? 0;
        $deviceSsrc = $body['device_ssrc'] ?? '';
        $sdp = $body['sdp'] ?? [];
        //{"device_id":"34020000002000000001","call_id":3,"dialog_id":4,"device_ssrc":"0790858880","device_ip":null,"device_port":"20006","sdp":{"version":"0","origin":{"username":"34020000001320456628","session_id":"0","session_version":"0","nettype":"IN","addrtype":"IP4","addr":"10.18.136.1"},"session_name":"Play","medias":[{"media":"video","port":"20006","proto":"RTP/AVP","payloads":["96"],"attributes":{"rtpmap":"96 PS/90000","sendonly":null}}],"gb28181":{"ssrc":"0790858880","f":"v/2////a/6//1"}},"timestamp":1768471090}
        Log::channel('sip')->info('Media ready', $body);

        if (!$callId) {
            Log::channel('sip')->warning('Media ready without call_id');
            return;
        }

        try {
            // 查找会话
            $session = $this->getDeviceService()->getSessionBySsrc((string)$deviceSsrc);
            if (!$session) {
                Log::channel('sip')->warning("{$deviceSsrc} Session not found");
                return;
            }

            // 更新会话状态和设备 SSRC
            $updateData = [
                'status' => StreamSessionStatus::Active->value,
                'device_ip' => !empty($body['device_ip']) ? $body['device_ip'] : $sdp['origin']['addr'] ?? null,
                'device_port' => $body['device_port'] ?? 0,
                'call_id' => $callId,
                'dialog_id' => $body['dialog_id'] ?? -1,
                'sdp' => isset($body['sdp']) ? serialize($sdp) : null,
            ];

            $this->getDeviceService()->updateChannelByMainId($session['stream_id'], [
                'stream_status' => ChannelStreamStatus::PUSHING->value,
            ]);
            $this->getDeviceService()->updateSessionBySSRC($deviceSsrc, $updateData);
            // 如果有设备 SSRC，更新 ZLM
//            if ($deviceSsrc && $streamId) {
//                try {
//                    $result = $this->getGb28181Service()->updateRtpServerSsrc($streamId, $deviceSsrc);
//
//                    if ($result) {
//                        Log::channel('sip')->info('ZLM SSRC updated', [
//                            'stream_id' => $streamId,
//                            'device_ssrc' => $deviceSsrc,
//                        ]);
//                    } else {
//                        Log::channel('sip')->warning('ZLM SSRC update failed', [
//                            'stream_id' => $streamId,
//                        ]);
//                    }
//                } catch (\Exception $e) {
//                    Log::channel('sip')->error('ZLM update error', [
//                        'stream_id' => $streamId,
//                        'error' => $e->getMessage(),
//                    ]);
//                }
//            }

        } catch (\Exception $e) {
            Log::channel('sip')->error('Media ready handler failed', [
                'call_id' => $callId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理语音对讲 INVITE
     */
    private function handleVoiceInvite(array $body): void
    {
        $deviceId = $body['device_id'] ?? '';
        $channelId = $body['channel_id'] ?? '';
        $mode = $body['mode'] ?? 'talk';

        Log::channel('sip')->info('Voice invite', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'mode' => $mode,
        ]);

        // TODO: 实现语音对讲业务逻辑
        // 1. 分配 ZLM 端口接收音频
        // 2. 生成 SDP 响应
        // 3. 返回音频推流地址给前端

        Log::channel('sip')->warning('Voice invite not implemented yet', [
            'device_id' => $deviceId,
        ]);
    }

    /**
     * 处理会话结束（BYE）
     */
    private function handleSessionBye(array $body): void
    {
        $callId = $body['call_id'] ?? 0;
        $deviceId = $body['device_id'] ?? '';

        Log::channel('sip')->info('Session bye', [
            'call_id' => $callId,
            'device_id' => $deviceId,
        ]);

        if (!$callId) {
            return;
        }

        try {
            // 查找会话
            $session = $this->getDeviceService()->getSessionByCallId((int)$callId);
            if (!$session) {
                Log::channel('sip')->warning('Session not found for BYE', ['call_id' => $callId]);
                return;
            }

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
                        'error' => $e->getMessage(),
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
                        'port' => $port,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 删除会话记录
            $this->getDeviceService()->deleteSessionByCallId((int)$callId);

            Log::channel('sip')->info('Session cleaned up', ['call_id' => $callId]);

        } catch (\Exception $e) {
            Log::channel('sip')->error('Session bye handler failed', [
                'call_id' => $callId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理设备状态变化
     */
    private function handleDeviceStatus(array $body): void
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
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Device Status update failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
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
                    'device_id' => $body['device_id'],
                    'channel_id' => $item['DeviceID'],
                    'name' => $item['Name'],
                    'file_path' => $item['FilePath'] ?? '',
                    'address' => $item['Address'] ?? '',
                    'start_time' => strtotime($item['StartTime']),
                    'end_time' => strtotime($item['EndTime']),
                    'secrecy' => (int)($item['Secrecy'] ?? 0),
                    'type' => $item['Type'] ?? 'time',
                    'recorder_id' => $item['RecorderID'] ?? '',
                ];
            }

            // 使用 savePlaybackRecords 自动去重
            $this->getPlaybackRecordService()->savePlaybackRecords($records);

            Log::channel('sip')->info('Playback records saved', [
                'device_id' => $body['device_id'],
                'count' => count($records),
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Playback records save failed', [
                'device_id' => $body['device_id'],
                'error' => $e->getMessage(),
            ]);
        }

        return;
    }

    private function handleDeviceInfo(array $body): void
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
                'device_name' => $info['DeviceName'] ?? $device['name'],
                'manufacturer' => $info['Manufacturer'] ?? $device['manufacturer'],
                'model' => $info['Model'] ?? $device['model'],
                'firmware' => $info['Firmware'] ?? $device['firmware'],
                'sum_num' => $info['Channel'] ?? $device['sum_num'],
            ]);

            Log::channel('sip')->info('Device Info changed', [
                'device_id' => $deviceId,
                'info' => $info,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Device Info update failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理报警信息
     */
    private function handleAlarm(array $body): void
    {
        $deviceId = $body['device_id'] ?? '';
        $priority = $body['priority'] ?? '1';
        $method = $body['method'] ?? '';

        Log::channel('sip')->warning('Device alarm', [
            'device_id' => $deviceId,
            'priority' => $priority,
            'method' => $method,
            'data' => $body['data'] ?? [],
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
    private function handleCommandConfirmed(array $body): void
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
            'device_id' => $deviceId,
            'call_id' => $callId,
            'cseq' => $cseq,
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
                    'device_id' => $deviceId,
                    'device_name' => $device['device_name'] ?? 'unknown',
                ]);
            }

        } catch (\Exception $e) {
            Log::channel('sip')->error('Handle command confirmed failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }


    /**
     * @return Gb28181Service
     */
    private function getGb28181Service(): Gb28181Service
    {
        return $this->createService('GB:Gb28181Service');
    }

    /**
     * @return PlaybackRecordService
     */
    private function getPlaybackRecordService(): PlaybackRecordService
    {
        return $this->createService('Devices:PlaybackRecordService');
    }

    /**
     * @return AlarmEventService
     */
    private function getAlarmEventService(): AlarmEventService
    {
        return $this->createService('Alarm:AlarmEventService');
    }

    /**
     * 处理目录变更通知（NOTIFY with Event: Catalog）
     * 设备主动推送目录变更时触发
     */
    private function handleCatalogUpdate(array $body): void
    {
        $deviceId = $body['device_id'] ?? '';
        $devices = $body['devices'] ?? [];

        if (!$deviceId || empty($devices)) {
            Log::channel('sip')->warning('Catalog update missing data', [
                'device_id' => $deviceId,
                'count' => count($devices),
            ]);
            return;
        }

        try {
            // 复用 handleCatalog 方法，逻辑完全相同
            $count = $this->getDeviceService()->batchUpdateOrCreateChannels($deviceId, $devices);

            Log::channel('sip')->info('Catalog update handled', [
                'device_id' => $deviceId,
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            Log::channel('sip')->error('Catalog update handler failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理报警事件通知（NOTIFY with Event: Alarm）
     * 设备主动推送报警时触发
     */
    private function handleAlarmEvent(array $body): void
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
                'device_id' => $deviceId,
                'channel_id' => $alarmData['channel_id'] ?? $deviceId,
                'level' => $alarmData['priority'] ?? $alarmData['level'] ?? 1,
                'method' => $alarmData['method'] ?? $alarmData['alarm_method'] ?? 1,
                'type' => $alarmData['alarm_type'] ?? $alarmData['type'] ?? null,
                'eventtype' => $alarmData['event_type'] ?? $alarmData['eventtype'] ?? null,
                'description' => $alarmData['description'] ?? $alarmData['alarm_description'] ?? '',
                'longitude' => $alarmData['longitude'] ?? null,
                'latitude' => $alarmData['latitude'] ?? null,
                'alarm_time' => $alarmData['alarm_time'] ?? date('Y-m-d H:i:s.v'),
                'recv_time' => date('Y-m-d H:i:s.v'),
                'raw_payload' => $body['raw_payload'] ?? json_encode($alarmData, JSON_UNESCAPED_UNICODE),
            ];

            // 调用 AlarmEventService 处理报警事件
            $event = $this->getAlarmEventService()->handleAlarmNotify($alarmEvent);

            Log::channel('sip')->info('Alarm event handled', [
                'event_id' => $event['id'],
                'device_id' => $deviceId,
                'channel_id' => $event['channel_id'],
                'level' => $event['level'],
                'alarm_plan_id' => $event['alarm_plan_id'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::channel('sip')->error('Alarm event handler failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理位置更新通知（NOTIFY with Event: presence）
     * 移动设备周期性推送位置时触发
     */
    private function handlePositionUpdate(array $body): void
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
                'device_id' => $deviceId,
                'longitude' => $positionData['longitude'] ?? 0,
                'latitude' => $positionData['latitude'] ?? 0,
                'speed' => $positionData['speed'] ?? 0,
                'direction' => $positionData['direction'] ?? 0,
                'altitude' => $positionData['altitude'] ?? 0,
                'record_time' => $positionData['time'] ?? $positionData['alarm_time'] ?? date('Y-m-d H:i:s'),
            ];

            // TODO: 保存位置信息到数据库
            // $this->getDevicePositionService()->savePosition($position);

            Log::channel('sip')->debug('Position update received', [
                'device_id' => $deviceId,
                'longitude' => $position['longitude'],
                'latitude' => $position['latitude'],
            ]);

        } catch (\Exception $e) {
            Log::channel('sip')->error('Position update handler failed', [
                'device_id' => $deviceId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 处理移动位置订阅确认
     * 订阅成功后网关推送确认
     */
    private function handleMobilePositionSubscribe(array $body): void
    {
        $deviceId = $body['device_id'] ?? '';
        $expires = $body['expires'] ?? 0;
        $interval = $body['interval'] ?? 5;

        Log::channel('sip')->info('Mobile position subscribed', [
            'device_id' => $deviceId,
            'expires' => $expires,
            'interval' => $interval,
        ]);

        // TODO: 更新订阅配置状态
        // $this->getSubscribeService()->markSubscriptionActive($deviceId, 'mobile_position');
    }

    /**
     * 处理移动位置取消订阅确认
     */
    private function handleMobilePositionUnsubscribe(array $body): void
    {
        $deviceId = $body['device_id'] ?? '';

        Log::channel('sip')->info('Mobile position unsubscribed', [
            'device_id' => $deviceId,
        ]);

        // TODO: 更新订阅配置状态
        // $this->getSubscribeService()->markSubscriptionCancelled($deviceId, 'mobile_position');
    }
}