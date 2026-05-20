<?php

namespace CoreW\Business\Devices\Handler;

use CoreW\Bfw;
use CoreW\Business\Devices\Dao\VoiceSessionDao;
use CoreW\Business\Devices\Service\Impl\VoiceTalkServiceImpl;
use CoreW\Business\Devices\Service\VoiceTalkService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Dao\DaoProxy;

/**
 * Broadcast 模式流到达处理器
 *
 * 广播模式流程（GB28181-2016/2022）：
 *
 * 第一步：冲突检查
 *   - 查询是否存在同设备同通道的未关闭的 talk 会话
 *     - 流存活 -> 拒绝（返回 false，"正在语音对讲"）
 *     - 流不在 -> stopTalk 清理
 *   - 查询是否存在同设备同通道的未关闭的 broadcast 会话
 *     - 流存活 -> 拒绝（返回 false，"正在语音广播"）
 *     - 流不在 -> stopAudioBroadcast 清理
 *
 * 第二步：更新 session（SSRC + receiveStream）
 *   - 仅更新数据库，此时不调用 ZLM（broadcast 的 ZLM 端口在设备 INVITE 到达后再开）
 *
 * 第三步：发送 SIP MESSAGE（CmdType=Broadcast）通知设备
 *   - 通知设备准备接收语音广播
 *   - 设备处理后主动发送 INVITE 给服务端
 *   - 注意：broadcast 不是服务端发 INVITE，而是设备发 INVITE
 *
 * 第四步：启动等待设备 INVITE 超时定时器
 *   - 通过 RedisQueue 延迟任务
 *   - 超时后（默认10s）如设备未回 INVITE，自动调 stopAudioBroadcast 终止流程
 *
 * 后续流程（由网关层处理）：
 *   - 设备发 INVITE -> 网关回调 setupBroadcastRtp -> ZLM startSendRtpPassive -> 网关回 200 OK
 */
class BroadcastStreamArrivalHandler implements StreamArrivalHandlerInterface
{
    /**
     * 等待设备 INVITE 超时时间（秒）
     */
    private const BROADCAST_INVITE_TIMEOUT = 30;

    /**
     * 超时任务 Redis 队列名称
     */
    private const TIMEOUT_QUEUE = 'voice_talk_timeout';

    private Bfw $bfw;

    public function __construct(Bfw $bfw)
    {
        $this->bfw = $bfw;
    }

    /**
     * 处理 Broadcast 模式流到达
     */
    public function handle(StreamArrivalContext $context) : bool
    {
        $session = $context->getSession();

        // === 第一步：冲突检查（防止 broadcast 与 talk/其他 broadcast 冲突） ===
        if (!$this->checkConflict($context)) {
            return false;
        }

        // === 第二步：更新 session（SSRC + receiveStream） ===
        // broadcast 模式此时不调 ZLM，ZLM 端口在设备 INVITE 到达后再开（setupBroadcastRtp）
        $updData = [
            'receive_stream' => $context->getReceiveStreamId(),
            'ssrc'           => $context->getSsrc(),
        ];
        $this->getVoiceSessionDao()->update($session['id'], $updData);
        $context->mergeSession($updData);

        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
            'Broadcast: SSRC 已分配，准备发送 MESSAGE（不调 ZLM）', [
                'session_id' => $context->getSessionId(),
                'ssrc'       => $context->getSsrc(),
                'rtp_port'   => $session['rtp_port'],
            ]);

        // === 第三步：发送 SIP MESSAGE（Broadcast）通知设备 ===
        $this->sendBroadcastNotify($context);

        // === 第四步：启动等待设备 INVITE 超时定时器 ===
        $this->startWaitInviteTimeoutTimer($context);

        return true;
    }

    /**
     * 冲突检查：防止 broadcast 与 talk/其他 broadcast 冲突
     *
     * WVP 流程：
     * 1. 查是否正在对讲（talk）：流存活 -> 拒绝；流不在 -> stopTalk 清理
     * 2. 查是否正在广播（broadcast 但不是自己）：流存活 -> 拒绝；流不在 -> stopAudioBroadcast 清理
     */
    private function checkConflict(StreamArrivalContext $context) : bool
    {
        $deviceId = $context->getDeviceId();
        $channelId = $context->getChannelId();

        // 检查是否存在 talk 会话
        $talkConflict = $this->checkExistingSession($context, 'talk');
        if ($talkConflict === false) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                'Broadcast: 冲突检查失败 - 设备正在语音对讲中', [
                    'device_id'  => $deviceId,
                    'channel_id' => $channelId,
                ]);
            return false;
        }

        // 检查是否存在其他 broadcast 会话（排除当前 session）
        $broadcastConflict = $this->checkExistingSession($context, 'broadcast');
        if ($broadcastConflict === false) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                'Broadcast: 冲突检查失败 - 设备正在语音广播中', [
                    'device_id'  => $deviceId,
                    'channel_id' => $channelId,
                ]);
            return false;
        }

        return true;
    }

    /**
     * 检查指定模式的现有会话
     *
     * 使用与 startVoiceTalk 一致的有效时间窗口查询，
     * 避免查到过期的历史会话产生误判。
     *
     * @param StreamArrivalContext $context
     * @param string $mode 要检查的模式（talk/broadcast）
     * @return bool true=无冲突或已清理, false=流存活需拒绝
     */
    private function checkExistingSession(StreamArrivalContext $context, string $mode) : bool
    {
        $deviceId = $context->getDeviceId();
        $channelId = $context->getChannelId();
        $currentSessionId = $context->getSessionId();

        $timeoutSeconds = (int)env('GB_VOICE_BROADCAST_WAIT_INVITE_TIMEOUT', self::BROADCAST_INVITE_TIMEOUT) + 5;

        $existingSessions = $this->getVoiceSessionDao()->findActiveByDeviceChannelAndMode(
            $deviceId,
            $channelId,
            $mode,
            $timeoutSeconds,
            $currentSessionId
        );

        foreach ($existingSessions as $existingSession) {
            $app = $existingSession['mode'] ?? 'talk';
            $streamReady = $this->getVoiceTalkService()->isStreamReady(
                $existingSession['media_server_id'],
                $app,
                $existingSession['stream']
            );

            if ($streamReady) {
                return false;
            }

            // 流不存在，清理僵尸会话
            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                "Broadcast: 清理 {$mode} 僵尸会话", [
                    'session_id' => $existingSession['session_id'],
                    'device_id'  => $deviceId,
                    'channel_id' => $channelId,
                ]);
            $this->getVoiceTalkService()->stopVoiceTalkBySession($existingSession, 'broadcast_conflict_cleanup');
        }

        return true;
    }

    /**
     * 发送 Broadcast MESSAGE 通知设备
     *
     * 广播模式流程（GB28181-2016/2022）：
     * 1. 服务端发送 MESSAGE（CmdType=Broadcast）通知设备
     * 2. 设备处理后主动发送 INVITE 给服务端（设备发起 INVITE）
     * 3. 网关回复 200 OK（携带 SDP，包含 ZLM 端口/SSRC）
     * 4. 设备 ACK
     * 5. ZLM 向设备推送音频流
     *
     * 注意：与 talk 模式不同，broadcast 模式的 INVITE 由设备发起。
     */
    private function sendBroadcastNotify(StreamArrivalContext $context) : void
    {
        $session = $context->getSession();

        // 映射 mode -> sdp_direction（broadcast -> recvonly，设备只接收音频）
        $session['sdp_direction'] = VoiceTalkServiceImpl::mapModeToSdpDirection($session['mode'] ?? 'broadcast');

        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
            'Broadcast: 发送 SIP MESSAGE 通知设备', [
                'session_id'     => $context->getSessionId(),
                'device_id'      => $context->getDeviceId(),
                'channel_id'     => $context->getChannelId(),
                'ssrc'           => $session['ssrc'] ?? null,
                'rtp_local_port' => $session['rtp_local_port'] ?? null,
            ]);

        $this->getGb28181Service()->startAudioBroadcast($session);
    }

    /**
     * 启动等待设备 INVITE 超时定时器
     *
     * 通过 RedisQueue 延迟任务实现。
     * 如果超时后设备未发送 INVITE，自动调用 stopAudioBroadcast 终止流程。
     */
    private function startWaitInviteTimeoutTimer(StreamArrivalContext $context) : void
    {
        $timeout = (int)env('GB_VOICE_BROADCAST_WAIT_INVITE_TIMEOUT', self::BROADCAST_INVITE_TIMEOUT);

        try {
            \Webman\RedisQueue\Client::send(self::TIMEOUT_QUEUE, [
                'type'       => 'broadcast_wait_invite_timeout',
                'session_id' => $context->getSessionId(),
                'device_id'  => $context->getDeviceId(),
                'channel_id' => $context->getChannelId(),
                'created_at' => date('Y-m-d H:i:s'),
            ], $timeout);

            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                "Broadcast: 启动等待设备 INVITE 超时定时器（{$timeout}s）", [
                    'session_id' => $context->getSessionId(),
                ]);
        } catch (\Throwable $e) {
            // 超时定时器启动失败不应阻断主流程，仅记录警告
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                'Broadcast: 启动超时定时器失败', [
                    'session_id' => $context->getSessionId(),
                    'error'      => $e->getMessage(),
                ]);
        }
    }

    // ============================================================
    // 依赖获取
    // ============================================================

    protected function getVoiceSessionDao() : VoiceSessionDao|DaoProxy
    {
        return $this->bfw->dao('Devices:VoiceSessionDao');
    }

    protected function getVoiceTalkService() : VoiceTalkService
    {
        return $this->bfw->service('Devices:VoiceTalkService');
    }

    protected function getGb28181Service() : Gb28181Service
    {
        return $this->bfw['gb28181_service'];
    }

    protected function getLogService() : SystemLogService
    {
        return $this->bfw->service('SystemLog:SystemLogService');
    }
}
