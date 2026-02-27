<?php

namespace CoreW\Business\Devices\Service\Impl;

use CoreW\Bfw;
use CoreW\Business\BaseService;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\Devices\Dao\VoiceSessionDao;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Enums\MediaServerStatus;
use CoreW\Business\Devices\Enums\VoiceSessionStatusEnum;
use CoreW\Business\Devices\Handler\BroadcastStreamArrivalHandler;
use CoreW\Business\Devices\Handler\StreamArrivalContext;
use CoreW\Business\Devices\Handler\StreamArrivalHandlerInterface;
use CoreW\Business\Devices\Handler\TalkStreamArrivalHandler;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Service\VoiceTalkService;
use CoreW\Business\GB\Dtos\VoiceTalkStreamArrivalDto;
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
            // 检查活跃会话对应的流是否真实存在于 ZLMediaKit 中
            // 如果流不存在，说明是异常残留的僵尸会话，需要先清理
            $app = $existingSession['mode'] ?? 'talk';
            $streamReady = $this->isStreamReady(
                $existingSession['media_server_id'],
                $app,
                $existingSession['stream']
            );

            if (!$streamReady) {
                // 流不存在，属于异常状态的僵尸会话，自动清理
                $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                    '发现僵尸会话（流不存在），自动清理', [
                        'device_id' => $deviceId,
                        'channel_id' => $channelId,
                        'existing_session_id' => $existingSession['session_id'],
                        'existing_status' => $existingSession['status'],
                        'stream' => $existingSession['stream'],
                    ]);

                $this->stopVoiceTalkBySession($existingSession, 'stale_session_cleanup');
            } else {
                // 流存在，说明确实在对讲中
                $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK, '设备正在对讲中', [
                    'device_id' => $deviceId,
                    'channel_id' => $channelId,
                    'existing_session_id' => $existingSession['session_id'],
                    'existing_status' => $existingSession['status'],
                ]);

                throw CommonBizException::ERROR_PARAMETER('设备正在对讲中，请稍后再试');
            }
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
        $srcPort = $this->getGb28181SendRtpPortService()->getNextPort($mediaServer);

        if ($srcPort < 0) {
            throw CommonBizException::ERROR_PARAMETER('无法分配RTP端口');
        }

        $zlmIp = $mediaServer['stream_ip'] ?? $mediaServer['ip'];
        $zlmClient = $this->getZlmClientByServerId($channel['media_server_id']);

        // 5. 设置过期时间
        $expiresAt = date('Y-m-d H:i:s', time() + env('GB_VOICE_TALK_SESSION_RECEIVE_INVITE_TIMEOUT', self::SESSION_TIMEOUT));

        // 6. 创建会话记录（状态为 WAITING_STREAM）
        // rtp_tcp: ZLM 被动收流传输协议, 0=UDP, 1=TCP
        // 默认 TCP，因为大多数 GB28181 设备 INVITE 使用 TCP/setup:active
        // 网关层会根据设备实际 SDP Offer 做最终 SDP 协商
        $rtpTcp = 1;

        $sessionData = [
            'session_id' => $sessionId,
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'stream' => $streamId,
            'rtp_port' => $srcPort,
            'media_server_ip' => $zlmIp,
            'mode' => $mode,
            'rtp_tcp' => $rtpTcp,
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
            'rtp_port' => $srcPort,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * 处理流到达事件（ZLM hook on_publish）
     *
     * 状态转换: WAITING_STREAM -> STREAM_ARRIVED -> (Handler) -> INVITING
     *
     * 此方法只负责：
     * 1. 根据 stream 查找 session
     * 2. CAS 状态转换 WAITING_STREAM -> STREAM_ARRIVED
     * 3. 获取媒体服务器并分配 SSRC
     * 4. 构建 StreamArrivalContext，委托给对应的 Handler
     * 5. Handler 成功后 CAS 状态转换 STREAM_ARRIVED -> INVITING
     *
     * 具体的信令流程由 Handler 实现：
     * - TalkStreamArrivalHandler: 互斥检查 -> 超时定时器 -> ZLM 开端口 -> SIP INVITE
     * - BroadcastStreamArrivalHandler: 冲突检查 -> SIP MESSAGE 通知 -> 等待设备 INVITE 超时定时器
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
        $updated = $this->getVoiceSessionDao()->updStatusIf(
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
            $this->markSessionFailed($session, 'media_server_not_found');
            return;
        }

        // 4. 分配 SSRC
        $ssrc = $this->getSsrcFactory()->getTalkSsrc($mediaServer['server_id']);
        if (!$ssrc) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '无法分配 SSRC，已经用完', ['media_server_id' => $session['media_server_id']]);
            $this->markSessionFailed($session, 'ssrc_exhausted');
            return;
        }

        // 5. 构建上下文
        // receiveStreamId 必须与用户推流 streamId 不同，避免 ZLM stream 和 recv_stream_id 冲突
        $receiveStreamId = $this->generateStreamId($session['device_id'], $session['channel_id'], $session['mode']) . '_recv';

        $context = new StreamArrivalContext($app, $stream, $mediaServerId, $session);
        $context->setSsrc($ssrc);
        $context->setReceiveStreamId($receiveStreamId);
        $context->setMediaServer($mediaServer);

        // 6. 根据 app 选择对应的 Handler 并执行
        $handler = $this->resolveStreamArrivalHandler($app);
        $success = $handler->handle($context);

        if (!$success) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '流到达处理失败', [
                    'app' => $app,
                    'session_id' => $session['session_id'],
                ]);
            $this->markSessionFailed($session, 'handler_failed');
            return;
        }

        // 7. CAS: STREAM_ARRIVED -> INVITING
        $this->getVoiceSessionDao()->updStatusIf(
            $session['id'],
            VoiceSessionStatusEnum::STREAM_ARRIVED->value,
            VoiceSessionStatusEnum::INVITING->value,
            ['expires_at' => date('Y-m-d H:i:s', time() + env('GB_VOICE_TALK_SESSION_RECEIVE_INVITE_TIMEOUT', self::SESSION_TIMEOUT))]
        );
    }

    /**
     * 根据 app（talk/broadcast）解析对应的流到达处理器
     *
     * @param string $app 应用名 (talk/broadcast)
     * @return StreamArrivalHandlerInterface
     */
    private function resolveStreamArrivalHandler(string $app): StreamArrivalHandlerInterface
    {
        // WVP 对齐：talk 和 broadcast 使用相同的广播流程（设备发起 INVITE）
        // talk 模式在 GB28181-2022 中已移除，WVP 也只处理与 broadcast 相同的逻辑
        return new BroadcastStreamArrivalHandler($this->bfw);
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
            'app' => $session['mode'] ?? 'talk',
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
        $updated = $this->getVoiceSessionDao()->updStatusIf(
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

        // 任意非 ENDED 状态 -> ENDED
        $updated = $this->getVoiceSessionDao()->endSession($session['id'], $reason);

        if (!$updated) {
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

            // 3. 停止 RTP / ZLM（先检查流是否存在，避免对不存在的流调用 stopSendRtp 报错）
            if (!empty($session['stream']) && !empty($session['ssrc'])) {
                try {
                    $app = $session['mode'] ?? 'talk';
                    $zlmClient = $this->getZlmClientByServerId($session['media_server_id']);
                    // 先检查流是否存在，不存在则跳过 stopSendRtp
                    $streamReady = $this->isStreamReady($session['media_server_id'], $app, $session['stream']);
                    if ($streamReady) {
                        $zlmClient->stopSendRtp('__defaultVhost__', $app, $session['stream'], $session['ssrc']);
                    }
//                    else {
//                        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
//                            '流已不存在，跳过 stopSendRtp', [
//                                'session_id' => $session['session_id'],
//                                'stream' => $session['stream'],
//                            ]);
//                    }
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

            // 5. 删除 sendRtpInfo 释放端口
            try {
                $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK, '删除 sendRtpInfo',  $session);
                $this->getGb28181SendRtpPortService()->deleteSendRtpInfo($session);
            } catch (\Throwable $e) {
                $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                    '删除 sendRtpInfo 失败', ['error' => $e->getMessage()]);
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
        $updated = $this->getVoiceSessionDao()->updStatusIf(
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
     * 根据 call_id 获取会话信息
     *
     * @return array|null
     */
    public function getSessionByCallId(string $callId): ?array
    {
        $result = $this->getVoiceSessionDao()->getByCallId($callId);
        return $result === false ? null : $result;
    }

    /**
     * 删除流
     */
    public function deleteStreamByStreamAndMediaServerId(string $stream, string $mediaServerId)
    {
        return $this->getVoiceSessionDao()->batchDelete(['stream' => $stream, 'media_server_id' => $mediaServerId]);
    }

    /**
     * 检查流是否在 ZLMediaKit 中存在（就绪状态）
     * 参考 WVP-PRO 的 isStreamReady 逻辑，通过 getMediaList 查询流是否存在
     *
     * @param string $mediaServerId 媒体服务器ID
     * @param string $app 应用名 (talk/broadcast)
     * @param string $stream 流ID
     * @return bool 流存在返回 true，不存在返回 false
     */
    public function isStreamReady(string $mediaServerId, string $app, string $stream): bool
    {
        try {
            $zlmClient = $this->getZlmClientByServerId($mediaServerId);
            $mediaList = $zlmClient->getMediaList($app, $stream);

            return !empty($mediaList);
        } catch (\Throwable $e) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '检查流状态异常，视为流不存在', [
                    'media_server_id' => $mediaServerId,
                    'app' => $app,
                    'stream' => $stream,
                    'error' => $e->getMessage(),
                ]);

            return false;
        }
    }

    /**
     * 为 Broadcast 设置 RTP（设备 INVITE 到达后调用）
     *
     * WVP 对齐时序：在收到设备 INVITE 后，根据设备 SDP 协商传输协议，
     * 再调用 ZLM startSendRtpPassive 开端口。
     *
     * @param string $sessionId 会话ID
     * @param array $deviceSdpInfo 设备 SDP 信息: device_transport, device_setup, device_ip, device_port
     * @return array 包含 local_port, media_server_ip, ssrc, tcp_mode
     */
    public function setupBroadcastRtp(string $sessionId, array $deviceSdpInfo): array
    {
        $session = $this->getVoiceSessionDao()->getBySessionId($sessionId);
        if (!$session) {
            throw CommonBizException::NOTFOUND_RESOURCE('会话不存在');
        }

        // 根据设备 SDP 的 transport 判断 TCP/UDP
        $deviceTransport = $deviceSdpInfo['device_transport'] ?? 'RTP/AVP';
        $isTcp = stripos($deviceTransport, 'TCP') !== false;

        $receiveStreamId = $session['receive_stream']
            ?: $this->generateStreamId($session['device_id'], $session['channel_id'], $session['mode']) . '_recv';

        // 调 ZLM startSendRtpPassive
        $dto = VoiceTalkStreamArrivalDto::create(
            $session['mode'],
            $session['stream'],
            $session['media_server_id'],
            $session['ssrc'],
            $session['rtp_port']
        )
            ->setReceiveStreamId($receiveStreamId)
            ->setPt(8)
            ->setUsePs(false)
            ->setOnlyAudio(true)
            ->setIsTcp($isTcp);

        $openResult = $this->getGb28181Service()->handleVoiceTalkStreamArrival($dto);

        if (!$openResult || $openResult['code'] != 0) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                'Broadcast setupBroadcastRtp: startSendRtpPassive 失败', [
                    'session_id' => $sessionId,
                    'error' => $openResult['msg'] ?? 'unknown',
                ]);
            throw CommonBizException::ERROR_PARAMETER('RTP 端口开设失败: ' . ($openResult['msg'] ?? 'unknown'));
        }

        // 更新 session
        $this->getVoiceSessionDao()->update($session['id'], [
            'rtp_local_port' => $openResult['local_port'],
            'rtp_tcp' => $isTcp ? 1 : 0,
            'receive_stream' => $receiveStreamId,
        ]);

        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
            'Broadcast setupBroadcastRtp: startSendRtpPassive 成功', [
                'session_id' => $sessionId,
                'local_port' => $openResult['local_port'],
                'is_tcp' => $isTcp,
            ]);

        // 计算 tcp_mode: 0=UDP, 1=TCP被动, 2=TCP主动
        $tcpMode = 0;
        if ($isTcp) {
            $deviceSetup = $deviceSdpInfo['device_setup'] ?? 'active';
            $tcpMode = ($deviceSetup === 'passive') ? 2 : 1;
        }

        return [
            'local_port' => $openResult['local_port'],
            'media_server_ip' => $session['media_server_ip'],
            'ssrc' => $session['ssrc'],
            'tcp_mode' => $tcpMode,
        ];
    }

    /**
     * 将应用层 mode 映射为合法的 SDP 媒体方向属性
     *
     * 合法的 SDP direction 值（RFC 3264）:
     * - sendrecv: 双向收发
     * - sendonly: 只发送
     * - recvonly: 只接收
     * - inactive: 不收不发
     *
     * @param string $mode 应用层模式 (talk/broadcast)
     * @return string 合法的 SDP direction 属性
     */
    public static function mapModeToSdpDirection(string $mode): string
    {
        return match ($mode) {
            'talk' => 'sendrecv',         // 双向对讲：平台和设备双向收发音频
            'broadcast' => 'recvonly',     // 广播：设备只接收音频（平台 -> 设备）
            default => $mode,             // 如果已经是合法的 SDP direction，保持原值
        };
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
