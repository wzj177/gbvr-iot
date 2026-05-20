<?php

namespace CoreW\Business\Devices\Handler;

use CoreW\Bfw;
use CoreW\Business\Devices\Dao\VoiceSessionDao;
use CoreW\Business\Devices\Service\Impl\VoiceTalkServiceImpl;
use CoreW\Business\Devices\Service\VoiceTalkService;
use CoreW\Business\GB\Dtos\VoiceTalkStreamArrivalDto;
use CoreW\Business\GB\Gb28181SendRtpPortService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Dao\DaoProxy;

/**
 * Talk 模式流到达处理器
 *
 * 对齐 WVP 的 talk() 流程，在前端推流到达 ZLM 后执行以下步骤：
 *
 * 第一步：互斥检查（防止 talk 与 broadcast 冲突）
 *   - 检查是否正在广播（broadcast）：流存活 -> 拒绝；流不在 -> stopAudioBroadcast 清理
 *   - 检查是否正在对讲（talk）：流存活 -> 拒绝；流不在 -> stopTalk 清理
 *
 * 第二步：申请资源
 *   - ssrcFactory.getTalkSsrc 申请 SSRC
 *   - 创建 sendRtpInfo，关键参数：app="talk", onlyAudio=true, pt=8, usePs=false
 *   - receiveStream = streamId（用于判断设备是否在推流）
 *
 * 第三步：启动超时定时器
 *   - 通过 RedisQueue 延迟任务，超时后触发 SIP BYE -> 释放 SSRC -> 清 session
 *
 * 第四步：ZLM 开启被动收流端口
 *   - startSendRtpPassive 让 ZLM 监听本地端口，等待设备建立 RTP 连接
 *   - 获取 localPort 写回 session
 *
 * 第五步：发 SIP INVITE 给设备
 *   - SDP 中携带 localPort（设备向此端口推流）以及平台侧推流给设备的地址
 *   - 200OK 回调由 onSipResponseOk 处理
 */
class TalkStreamArrivalHandler implements StreamArrivalHandlerInterface
{
    /**
     * INVITE 超时时间（秒），超时后自动清理会话
     */
    private const INVITE_TIMEOUT = 15;

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
     * 处理 Talk 模式流到达
     */
    public function handle(StreamArrivalContext $context) : bool
    {
        $session = $context->getSession();

        // === 第一步：互斥检查（防止 talk 与 broadcast 冲突） ===
        if (!$this->checkMutualExclusion($context)) {
            return false;
        }

        // === 第二步：申请资源（SSRC + sendRtpInfo 参数构建） ===
        $isTcp = !empty($session['rtp_tcp']);

        $dto = VoiceTalkStreamArrivalDto::create(
            $context->getApp(),
            $context->getStream(),
            $context->getMediaServerId(),
            $context->getSsrc(),
            $session['rtp_port']
        )
            ->setReceiveStreamId($context->getReceiveStreamId())
            ->setPt(8)              // PCMA (a=rtpmap:8 PCMA/8000)
            ->setUsePs(false)       // 语音对讲不需要 PS 封装
            ->setOnlyAudio(true)    // 仅音频
            ->setIsTcp($isTcp);     // 从 session.rtp_tcp 读取

        // === 第三步：启动超时定时器（在发 INVITE 之前启动，防止 INVITE 无响应卡住） ===
        $this->startInviteTimeoutTimer($context);

        // === 第四步：ZLM 开启被动收流端口 ===
        $openResult = $this->getGb28181Service()->handleVoiceTalkStreamArrival($dto);

        if (!$openResult || $openResult['code'] != 0) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                'Talk: startSendRtpPassive 失败', [
                    'session_id' => $context->getSessionId(),
                    'error'      => $openResult['msg'] ?? 'unknown',
                ]);
            return false;
        }

        // 更新端口信息到 session
        $updData = [
            'receive_stream' => $context->getReceiveStreamId(),
            'ssrc'           => $context->getSsrc(),
            'rtp_local_port' => $openResult['local_port'],
        ];
        $this->getVoiceSessionDao()->update($session['id'], $updData);
        $context->mergeSession($updData);

        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
            'Talk: startSendRtpPassive 成功', [
                'session_id'     => $context->getSessionId(),
                'rtp_local_port' => $openResult['local_port'],
                'rtp_tcp'        => $session['rtp_tcp'],
            ]);

        // === 第五步：发 SIP INVITE 给设备 ===
        $this->sendTalkInvite($context);

        return true;
    }

    /**
     * 互斥检查：防止 talk 与 broadcast 冲突
     *
     * WVP 流程：
     * 1. 查是否正在广播（broadcast）：流存活 -> 拒绝；流不在 -> stopAudioBroadcast 清理
     * 2. 查是否正在对讲（talk 但不是自己）：流存活 -> 拒绝；流不在 -> stopTalk 清理
     */
    private function checkMutualExclusion(StreamArrivalContext $context) : bool
    {
        $deviceId = $context->getDeviceId();
        $channelId = $context->getChannelId();

        // 检查是否存在 broadcast 会话
        $broadcastConflict = $this->checkExistingSession($context, 'broadcast');
        if ($broadcastConflict === false) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                'Talk: 互斥检查失败 - 设备正在语音广播中', [
                    'device_id'  => $deviceId,
                    'channel_id' => $channelId,
                ]);
            return false;
        }

        // 检查是否存在其他 talk 会话（排除当前 session）
        $talkConflict = $this->checkExistingSession($context, 'talk');
        if ($talkConflict === false) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                'Talk: 互斥检查失败 - 设备正在语音对讲中', [
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
        $voiceTalkService = $this->getVoiceTalkService();
        $deviceId = $context->getDeviceId();
        $channelId = $context->getChannelId();
        $currentSessionId = $context->getSessionId();

        $timeoutSeconds = (int)env('GB_VOICE_TALK_SESSION_RECEIVE_INVITE_TIMEOUT', self::INVITE_TIMEOUT) + 5;

        $existingSessions = $this->getVoiceSessionDao()->findActiveByDeviceChannelAndMode(
            $deviceId,
            $channelId,
            $mode,
            $timeoutSeconds,
            $currentSessionId
        );

        foreach ($existingSessions as $existingSession) {
            $app = $existingSession['mode'] ?? 'talk';
            $streamReady = $voiceTalkService->isStreamReady(
                $existingSession['media_server_id'],
                $app,
                $existingSession['stream']
            );

            if ($streamReady) {
                return false;
            }

            // 流不存在，清理僵尸会话
            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                "Talk: 清理 {$mode} 僵尸会话", [
                    'session_id' => $existingSession['session_id'],
                    'device_id'  => $deviceId,
                    'channel_id' => $channelId,
                ]);
            $voiceTalkService->stopVoiceTalkBySession($existingSession, 'mutual_exclusion_cleanup');
        }

        return true;
    }

    /**
     * 启动 INVITE 超时定时器
     *
     * 通过 RedisQueue 延迟任务实现，超时后触发会话清理。
     * 超时触发流程：检查会话状态 -> 如仍在 INVITING 则调用 stopVoiceTalkBySession
     */
    private function startInviteTimeoutTimer(StreamArrivalContext $context) : void
    {
        $timeout = (int)env('GB_VOICE_TALK_SESSION_RECEIVE_INVITE_TIMEOUT', self::INVITE_TIMEOUT);

        try {
            \Webman\RedisQueue\Client::send(self::TIMEOUT_QUEUE, [
                'type'       => 'talk_invite_timeout',
                'session_id' => $context->getSessionId(),
                'device_id'  => $context->getDeviceId(),
                'channel_id' => $context->getChannelId(),
                'created_at' => date('Y-m-d H:i:s'),
            ], $timeout);

            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                "Talk: 启动 INVITE 超时定时器（{$timeout}s）", [
                    'session_id' => $context->getSessionId(),
                ]);
        } catch (\Throwable $e) {
            // 超时定时器启动失败不应阻断主流程，仅记录警告
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                'Talk: 启动超时定时器失败', [
                    'session_id' => $context->getSessionId(),
                    'error'      => $e->getMessage(),
                ]);
        }
    }

    /**
     * 发送 Talk INVITE
     *
     * 将应用层 mode (talk) 映射为 SDP 媒体方向属性 sendrecv（双向对讲）
     */
    private function sendTalkInvite(StreamArrivalContext $context) : void
    {
        $session = $context->getSession();

        // 映射 mode -> sdp_direction（合法的 SDP 媒体方向属性）
        $session['sdp_direction'] = VoiceTalkServiceImpl::mapModeToSdpDirection($session['mode'] ?? 'talk');

        $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
            'Talk: 发送 SIP INVITE', [
                'session_id'     => $context->getSessionId(),
                'device_id'      => $context->getDeviceId(),
                'channel_id'     => $context->getChannelId(),
                'rtp_local_port' => $session['rtp_local_port'] ?? null,
                'ssrc'           => $session['ssrc'] ?? null,
            ]);

        $this->getGb28181Service()->startVoiceTalk($session);
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

    protected function getGb28181SendRtpPortService() : Gb28181SendRtpPortService
    {
        return $this->bfw['gb28181_send_rtp_port_service'];
    }

    protected function getLogService() : SystemLogService
    {
        return $this->bfw->service('SystemLog:SystemLogService');
    }
}
