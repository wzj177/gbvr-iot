<?php

namespace app\api\v2\controller;

use app\api\BaseController;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Sdk\PSipGateway\Gb28181Client;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Log;
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
class GBServerHockController extends BaseController
{

    public function index(Request $request): \support\Response
    {
        $scene = $request->post('scene');
        $body = $request->post('body', []);

        Log::channel('sip')->info('GBServer Hook Received', [
            'scene' => $scene,
            'body' => $body,
        ]);

        try {
            match ($scene) {
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
                'alarm' => $this->handleAlarm($body),
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

            $streamId = $session['stream_id'] ?? '';

            // 更新会话状态和设备 SSRC
            $updateData = [
                'status' => 'active',
                'device_ip' => $sdp['device_ip'] ?? null,
                'device_port' => $sdp['device_port'] ?? null,
                'call_id' => $callId,
                'sdp' => isset($body['sdp']) ? serialize($sdp) : null,
            ];

            if ($deviceSsrc) {
                $updateData['device_ssrc'] = $deviceSsrc;
            }
            $this->getDeviceService()->updateChannelByMainId($session['stream_id'], [
                'stream_status' => 'pushing'
            ]);
            $this->getDeviceService()->updateSessionByCallId((int)$callId, $updateData);

            // 如果有设备 SSRC，更新 ZLM
            if ($deviceSsrc && $streamId) {
                try {
                    $result = $this->getGb28181Service()->updateRtpServerSsrc($streamId, $deviceSsrc);

                    if ($result) {
                        Log::channel('sip')->info('ZLM SSRC updated', [
                            'stream_id' => $streamId,
                            'device_ssrc' => $deviceSsrc,
                        ]);
                    } else {
                        Log::channel('sip')->warning('ZLM SSRC update failed', [
                            'stream_id' => $streamId,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::channel('sip')->error('ZLM update error', [
                        'stream_id' => $streamId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 获取播放地址
            if ($streamId) {
                try {
                    $playUrls = $this->getGb28181Service()->getPlayUrls('rtp', $streamId);

                    // 更新会话的播放地址
                    $this->getDeviceService()->updateSessionByCallId((int)$callId, [
                        'play_urls' => json_encode($playUrls),
                    ]);

                    Log::channel('sip')->info('Play URLs generated', [
                        'stream_id' => $streamId,
                        'urls' => $playUrls,
                    ]);
                } catch (\Exception $e) {
                    Log::channel('sip')->warning('Get play URLs failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
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
            $port = $session['zlm_port'] ?? 0;

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
}