<?php

namespace CoreW\Business\Devices\Service\Impl;

use CoreW\Bfw;
use CoreW\Business\BaseService;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\Devices\Dao\VoiceSessionDao;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Enums\MediaServerStatus;
use CoreW\Business\Devices\Enums\VoiceSessionStatusEnum;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Service\VoiceTalkService;
use CoreW\Business\GB\Gb28181SendRtpPortService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\GB\SSRCFactory;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Dao\DaoProxy;
use Ramsey\Uuid\Uuid;

/**
 * GB28181 语音对讲服务实现
 *
 * 状态机流程：
 * WAITING_STREAM -> STREAM_ARRIVED -> INVITING -> CONNECTED -> ENDED
 *                  |                |             |
 *                  v                v             v
 *                FAILED <---------+-------------+
 *                  |
 *                  v
 *                ENDED
 *
 * 关键设计原则：
 * 1. 所有状态更新使用 CAS (Compare-And-Set)
 * 2. stopVoiceTalkBySession 是唯一的资源清理点
 * 3. 超时只改变状态，不做副作用操作
 * 4. 所有操作必须幂等
 */
class VoiceTalkServiceImpl extends BaseService implements VoiceTalkService
{
    /**
     * 会话超时时间（秒）
     */
    private const SESSION_TIMEOUT = 15;


    /**
     * 准备语音对讲
     *
     * @return array
     */
    public function prepareTalk(string $deviceId, string $channelId, string $mode = 'talk'): array
    {
        // 1. 验证设备和通道
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);
        if (!$device) {
            throw CommonBizException::NOTFOUND_RESOURCE('设备不存在');
        }

        if ($device['status'] !== DeviceStatusEnum::ONLINE->value) {
            throw CommonBizException::ERROR_PARAMETER('设备未在线');
        }

        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);
        if (!$channel) {
            throw CommonBizException::NOTFOUND_RESOURCE('通道不存在');
        }

        // 2. 检查是否已有活跃对讲会话
        $existingSession = $this->getVoiceSessionDao()->getActiveByDeviceAndChannel(
            $deviceId,
            $channelId,
            env('GB_VOICE_TALK_SESSION_RECEIVE_INVITE_TIMEOUT', self::SESSION_TIMEOUT) + 5 // 稍微放宽检查范围
        );

        if ($existingSession !== false) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK, '设备正在对讲中', [
                'device_id' => $deviceId,
                'channel_id' => $channelId,
                'existing_session_id' => $existingSession['session_id'],
                'existing_status' => $existingSession['status'],
            ]);

            throw CommonBizException::ERROR_PARAMETER('设备正在对讲中，请稍后再试');
        }

        // 3. 获取媒体服务器配置
        $mediaServer = $this->getMediaService()->getMediaServerByServerId($channel['media_server_id']);
        if (!$mediaServer) {
            throw CommonBizException::NOTFOUND_RESOURCE('媒体服务器未配置');
        }

        if ($mediaServer['status'] !== MediaServerStatus::RUNNING->value) {
            throw CommonBizException::ERROR_PARAMETER_MISSING('媒体服务器未在线');
        }

        // 4. 生成会话参数
        $sessionId = Uuid::uuid4()->toString();
        $streamId = $this->generateStreamId($deviceId, $channelId, $mode);
        $ssrc = $this->getSsrcFactory()->getTalkSsrc($mediaServer['server_id']);
        $srcPort = $this->getGb28181SendRtpPortService()->getNextPort($mediaServer);

        if ($srcPort < 0) {
            throw CommonBizException::ERROR_PARAMETER('无法分配RTP端口');
        }

        $zlmIp = $mediaServer['stream_ip'] ?? $mediaServer['ip'];
        $zlmClient = $this->getZlmClientByServerId($channel['media_server_id']);

        // 5. 设置过期时间
        $expiresAt = date('Y-m-d H:i:s', time() + env('GB_VOICE_TALK_SESSION_RECEIVE_INVITE_TIMEOUT', self::SESSION_TIMEOUT));

        // 6. 创建会话记录（状态为 WAITING_STREAM）
        $sessionData = [
            'session_id' => $sessionId,
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'stream' => $streamId,
            'ssrc' => $ssrc,
            'rtp_port' => $srcPort,
            'media_server_ip' => $zlmIp,
            'mode' => $mode,
            'media_server_id' => $mediaServer['server_id'],
            'started_at' => date('Y-m-d H:i:s'),
            'status' => VoiceSessionStatusEnum::WAITING_STREAM->value,
            'expires_at' => $expiresAt,
            'version' => 0,
        ];

        $this->getVoiceSessionDao()->create($sessionData);

        // 7. 生成推流地址
        $pushUrls = $zlmClient->getPushUrls($streamId, $mode, $mediaServer['access_url'] ?? $mediaServer['stream_ip']);

        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK, '语音对讲准备完成，等待前端推流', $sessionData);

        return [
            'session_id' => $sessionId,
            'stream_id' => $streamId,
            'mode' => $mode,
            'status' => $sessionData['status'],
            'streams' => $pushUrls,
            'ssrc' => $ssrc,
            'rtp_port' => $srcPort,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * 处理流到达事件（ZLM hook on_publish）
     *
     * 状态转换: WAITING_STREAM -> STREAM_ARRIVED -> INVITING
     */
    public function handleStreamArrival(string $app, string $stream, string $mediaServerId): void
    {
        // 1. 根据 stream 查找 session
        $session = $this->getVoiceSessionDao()->getByStreamAndMediaServerId($stream, $mediaServerId);
        if (!$session) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '未找到对应的语音会话', ['app' => $app, 'stream' => $stream, 'mediaServerId' => $mediaServerId]);
            return;
        }

        // 2. CAS: WAITING_STREAM -> STREAM_ARRIVED
        $updated = $this->getVoiceSessionDao()->updateStatusIf(
            $session['id'],
            VoiceSessionStatusEnum::WAITING_STREAM->value,
            VoiceSessionStatusEnum::STREAM_ARRIVED->value
        );

        if (!$updated) {
            // 状态已经不是 WAITING_STREAM，可能是已超时或已结束
            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '会话状态已变更，跳过流到达处理', [
                    'session_id' => $session['session_id'],
                    'current_status' => $session['status'],
                ]);
            return;
        }

        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_STREAM_ARRIVAL, '语音流到达，开始发起信令', [
            'app' => $app,
            'stream' => $stream,
            'session_id' => $session['session_id'],
        ]);

        // 3. 获取媒体服务器
        $mediaServer = $this->getMediaService()->getMediaServerByServerId($session['media_server_id']);
        if (!$mediaServer) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '媒体服务器不存在', ['media_server_id' => $session['media_server_id']]);

            // 标记为失败
            $this->markSessionFailed($session, 'media_server_not_found');
            return;
        }

        // 4. 调用 startSendRtpPassive
        $zlmClient = $this->getZlmClientByServerId($session['media_server_id']);
        $receiveStreamId = $this->generateStreamId($session['device_id'], $session['channel_id'], 'talk');

        // 检查是否已存在
        $mediaInfos = $zlmClient->getMediaList($app, $receiveStreamId);
        if (!empty($mediaInfos)) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '对讲会话已存在', [
                    'device_id' => $session['device_id'],
                    'channel_id' => $session['channel_id'],
                    'stream_id' => $stream,
                ]);
            $this->markSessionFailed($session, 'duplicate_stream');
            return;
        }

        $openResult = $zlmClient->startSendRtpPassive(
            '__defaultVhost__',
            $app,
            $receiveStreamId,
            $session['ssrc'],
            $session['rtp_port'],
            8,
            0,
            1
        );

        if ($openResult['code'] != 0) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                'startSendRtpPassive 失败', [
                    'session_id' => $session['session_id'],
                    'error' => $openResult['msg'] ?? 'unknown',
                ]);
            $this->markSessionFailed($session, 'rtp_open_failed');
            return;
        }

        // 更新端口信息
        $this->getVoiceSessionDao()->update($session['id'], [
            'receive_stream' => $receiveStreamId,
            'rtp_local_port' => $openResult['local_port'],
            'app' => $app,
        ]);

        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
            'startSendRtpPassive 成功', [
                'session_id' => $session['session_id'],
                'rtp_local_port' => $openResult['local_port'],
            ]);

        // 5. 发送 SIP 信令
        if ($app === 'talk') {
            $this->sendTalkInvite($session, $mediaServer, $openResult['local_port']);
        } else if ($app === 'broadcast') {
            $this->sendBroadcastNotify($session, $mediaServer, $openResult['local_port']);
        }

        // 6. CAS: STREAM_ARRIVED -> INVITING
        $this->getVoiceSessionDao()->updateStatusIf(
            $session['id'],
            VoiceSessionStatusEnum::STREAM_ARRIVED->value,
            VoiceSessionStatusEnum::INVITING->value,
            ['expires_at' => date('Y-m-d H:i:s', time() + env('GB_VOICE_TALK_SESSION_RECEIVE_INVITE_TIMEOUT', self::SESSION_TIMEOUT))]
        );
    }

    /**
     * SIP 200 OK 回调处理
     *
     * 状态转换: INVITING -> CONNECTED
     */
    public function onSipResponseOk(string $sessionId, array $sipResponse): void
    {
        $session = $this->getVoiceSessionDao()->getBySessionId($sessionId);
        if (!$session) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '会话不存在', ['session_id' => $sessionId]);
            return;
        }

        // 保存 sendRtpInfo
        $sendRtpInfo = [
            'call_id' => $sipResponse['call_id'],
            'device_id' => $session['device_id'],
            'channel_id' => $session['channel_id'],
            'stream' => $session['stream'],
            'app' => $session['app'] ?? 'talk',
            'ssrc' => $session['ssrc'],
            'port' => $session['rtp_port'],
            'local_port' => $session['rtp_local_port'],
        ];
        $this->getGb28181SendRtpPortService()->saveSendRtpInfo($sendRtpInfo);

        // 更新 dialog_id 和 call_id
        $this->getVoiceSessionDao()->update($session['id'], [
            'dialog_id' => $sipResponse['dialog_id'] ?? null,
            'call_id' => $sipResponse['call_id'],
        ]);

        // CAS: INVITING -> CONNECTED
        $updated = $this->getVoiceSessionDao()->updateStatusIf(
            $session['id'],
            VoiceSessionStatusEnum::INVITING->value,
            VoiceSessionStatusEnum::CONNECTED->value
        );

        if (!$updated) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '会话状态不匹配，无法更新为 CONNECTED', [
                    'session_id' => $sessionId,
                    'current_status' => $session['status'],
                ]);
        } else {
            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '语音对讲已建立', ['session_id' => $sessionId]);
        }
    }

    /**
     * 停止语音对讲（通过 session_id）
     *
     * @return array
     */
    public function stopVoiceTalk(string $sessionId): array
    {
        $session = $this->getVoiceSessionDao()->getBySessionId($sessionId);
        if (!$session) {
            return [
                'success' => false,
                'message' => '未找到对讲会话'
            ];
        }

        return $this->stopVoiceTalkBySession($session, 'manual');
    }

    /**
     * 停止语音对讲（统一的资源清理入口）
     *
     * 必须满足：
     * - 可重复调用（幂等）
     * - 状态已 ENDED 直接返回
     * - 所有外部调用都走这里
     *
     * 状态转换: 任意状态 -> ENDED
     *
     * @return array
     */
    public function stopVoiceTalkBySession(array $session, string $reason = 'manual'): array
    {
        // 1. CAS: 任意非 ENDED 状态 -> ENDED
        // 先尝试从当前状态更新到 ENDED
        $currentStatus = $session['status'];
        if ($currentStatus === VoiceSessionStatusEnum::ENDED->value) {
            return [
                'success' => true,
                'message' => '语音对讲已停止'
            ];
        }

        // 使用原生 SQL 实现多状态 CAS
        $sql = "UPDATE {$this->getVoiceSessionDao()->table()}
                SET status = :new_status,
                    version = version + 1,
                    ended_at = :ended_at,
                    ended_reason = :ended_reason,
                    updated_at = :updated_at
                WHERE id = :id
                AND status != :ended_status";

        $params = [
            'id' => $session['id'],
            'new_status' => VoiceSessionStatusEnum::ENDED->value,
            'ended_status' => VoiceSessionStatusEnum::ENDED->value,
            'ended_at' => date('Y-m-d H:i:s'),
            'ended_reason' => $reason,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $affectedRows = $this->getVoiceSessionDao()->db()->executeStatement($sql, $params);

        if ($affectedRows === 0) {
            // 已经是 ENDED 状态
            return [
                'success' => true,
                'message' => '语音对讲已停止'
            ];
        }

        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
            '停止语音对讲', [
                'session_id' => $session['session_id'],
                'reason' => $reason,
                'previous_status' => $currentStatus,
            ]);

        try {
            // 2. 发送 BYE（如果有 dialog_id）
            if (!empty($session['dialog_id']) && !empty($session['rtp_local_port'])) {
                $this->getGb28181Service()->stopVoiceTalk($session);
            }

            // 3. 停止 RTP / ZLM
            if (!empty($session['stream']) && !empty($session['ssrc'])) {
                try {
                    $zlmClient = $this->getZlmClientByServerId($session['media_server_id']);
                    $app = $session['app'] ?? 'talk';
                    $zlmClient->stopSendRtp('__defaultVhost__', $app, $session['stream'], $session['ssrc']);
                } catch (\Throwable $e) {
                    $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                        '停止 RTP 失败（可能已停止）', ['error' => $e->getMessage()]);
                }
            }

            // 4. 释放 SSRC
            if (!empty($session['ssrc']) && !empty($session['media_server_id'])) {
                try {
                    $this->getSsrcFactory()->releaseSsrc($session['media_server_id'], $session['ssrc']);
                } catch (\Throwable $e) {
                    $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                        '释放 SSRC 失败（可能已释放）', ['error' => $e->getMessage()]);
                }
            }

            // 5. 删除 sendRtpInfo
            if (!empty($session['call_id'])) {
                try {
                    $this->getGb28181SendRtpPortService()->deleteSendRtpInfo($session);
                } catch (\Throwable $e) {
                    $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                        '删除 sendRtpInfo 失败', ['error' => $e->getMessage()]);
                }
            }

            return [
                'success' => true,
                'message' => '语音对讲已停止'
            ];

        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK, '停止语音对讲异常', [
                'session' => $session,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => '停止对讲失败: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 处理流离开事件（ZLM hook on_unpublish）
     *
     * @return void
     */
    public function handleStreamDeparture(string $app, string $stream, string $mediaServerId): void
    {
        $session = $this->getVoiceSessionDao()->getByNoEndedStreamAndMediaServerId($stream, $mediaServerId);
        if ($session) {
            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_STREAM_DEPARTURE,
                '语音流离开，清理资源', [
                    'app' => $app,
                    'stream' => $stream,
                    'session_id' => $session['session_id'],
                ]);
            $this->stopVoiceTalkBySession($session, 'stream_departure');
        }
    }

    /**
     * 处理超时会话（定时任务调用）
     *
     * 只更新状态为 FAILED，不做资源清理
     * 资源清理由 stopVoiceTalkBySession 统一处理
     *
     * @return int
     */
    public function processTimeouts(): int
    {
        $expiredSessions = $this->getVoiceSessionDao()->findExpiredSessions(100);

        $count = 0;
        foreach ($expiredSessions as $session) {
            $this->markSessionFailed($session, 'timeout');
            $count++;
        }

        if ($count > 0) {
            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '处理超时会话', ['count' => $count]);
        }

        return $count;
    }

    /**
     * 标记会话为失败状态
     *
     * @param array $session
     * @param string $reason
     * @return void
     */
    private function markSessionFailed(array $session, string $reason): void
    {
        // CAS: 当前状态 -> FAILED
        $updated = $this->getVoiceSessionDao()->updateStatusIf(
            $session['id'],
            $session['status'],
            VoiceSessionStatusEnum::FAILED->value,
            [
                'ended_reason' => $reason,
                'ended_at' => date('Y-m-d H:i:s'),
            ]
        );

        if ($updated) {
            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '会话标记为失败', [
                    'session_id' => $session['session_id'],
                    'reason' => $reason,
                    'previous_status' => $session['status'],
                ]);

            // 失败后自动清理资源
            $this->stopVoiceTalkBySession($session, $reason);
        }
    }

    /**
     * 获取会话信息
     *
     * @return array|null
     */
    public function getSession(string $sessionId): ?array
    {
        return $this->getVoiceSessionDao()->getBySessionId($sessionId);
    }

    /**
     * 删除流
     */
    public function deleteStreamByStreamAndMediaServerId(string $stream, string $mediaServerId)
    {
        return $this->getVoiceSessionDao()->batchDelete(['stream' => $stream, 'media_server_id' => $mediaServerId]);
    }

    /**
     * 发送 Talk INVITE
     */
    private function sendTalkInvite(array $session, array $mediaServer, int $localPort): void
    {
        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
            '发送 Talk INVITE', $session);

        $this->getGb28181Service()->startVoiceTalk($session);
    }

    /**
     * 发送 Broadcast MESSAGE 通知
     */
    private function sendBroadcastNotify(array $session, array $mediaServer, int $localPort): void
    {
        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
            '发送 Broadcast MESSAGE', [
                'session_id' => $session['session_id'],
                'device_id' => $session['device_id'],
                'channel_id' => $session['channel_id'],
            ]);
    }

    /**
     * 生成流ID
     */
    private function generateStreamId(string $deviceId, string $channelId, string $mode): string
    {
        return $mode . '_' . $deviceId . '_' . $channelId;
    }

    protected function getSsrcFactory(): SSRCFactory
    {
        return $this->bfw['SSRCFactory'];
    }

    protected function getVoiceSessionDao(): VoiceSessionDao|DaoProxy
    {
        return $this->createDao('Devices:VoiceSessionDao');
    }

    protected function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * @return Gb28181Service
     */
    protected function getGb28181Service(): Gb28181Service
    {
        return $this->bfw['gb28181_service'];
    }

    protected function getGb28181SendRtpPortService(): Gb28181SendRtpPortService
    {
        return $this->bfw['gb28181_send_rtp_port_service'];
    }

    protected function getMediaService(): MediaServerService
    {
        return $this->createService('MediaServer:MediaServerService');
    }

    protected function getZlmClientByServerId(string $serverId): \CoreW\Sdk\ZLMediaKit\ZLMClient
    {
        return $this->getGb28181Service()->getZlmClientByServerId($serverId);
    }
}
