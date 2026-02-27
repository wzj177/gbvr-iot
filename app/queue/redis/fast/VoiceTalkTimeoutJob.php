<?php

namespace app\queue\redis\fast;

use CoreW\Bfw;
use CoreW\Business\Devices\Enums\VoiceSessionStatusEnum;
use CoreW\Business\Devices\Service\VoiceTalkService;
use CoreW\Business\SystemLog\LogEnum;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Core;
use Webman\RedisQueue\Consumer;

/**
 * 语音对讲/广播超时处理 Job
 *
 * 消费 voice_talk_timeout 队列，处理两种超时场景：
 * - talk_invite_timeout:          Talk 模式发出 INVITE 后设备无响应
 * - broadcast_wait_invite_timeout: Broadcast 模式发出 MESSAGE 后设备未发 INVITE
 *
 * 超时触发条件：会话仍处于 INVITING 或 STREAM_ARRIVED 状态（说明信令未完成）
 * 处理动作：调用 stopVoiceTalkBySession 统一清理（SIP BYE、释放 SSRC、释放端口、更新状态）
 */
class VoiceTalkTimeoutJob implements Consumer
{
    public $queue = 'voice_talk_timeout';

    public $connection = 'default';

    public function consume($data): bool
    {
        $type = $data['type'] ?? '';
        $sessionId = $data['session_id'] ?? '';

        if (!$sessionId) {
            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '超时任务缺少 session_id', ['data' => $data]);
            return false;
        }

        try {
            $session = $this->getVoiceTalkService()->getSession($sessionId);

            if (!$session) {
                // 会话已被删除，无需处理
                return true;
            }

            $status = $session['status'] ?? '';

            // 只有仍在等待信令完成的状态才需要超时清理
            $pendingStatuses = [
                VoiceSessionStatusEnum::STREAM_ARRIVED->value,
                VoiceSessionStatusEnum::INVITING->value,
            ];

            if (!in_array($status, $pendingStatuses)) {
                // 会话已完成（CONNECTED）或已结束（ENDED/FAILED），跳过
//                $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
//                    "超时任务跳过: 会话状态已变更为 {$status}", [
//                        'type' => $type,
//                        'session_id' => $sessionId,
//                    ]);
                return true;
            }

            $reason = match ($type) {
                'talk_invite_timeout' => 'talk_invite_timeout',
                'broadcast_wait_invite_timeout' => 'broadcast_wait_invite_timeout',
                default => 'unknown_timeout',
            };

            $this->getLogService()->warning(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                "语音会话超时，执行清理: type={$type}", [
                    'session_id' => $sessionId,
                    'device_id' => $data['device_id'] ?? '',
                    'channel_id' => $data['channel_id'] ?? '',
                    'status' => $status,
                ]);

            $this->getVoiceTalkService()->stopVoiceTalkBySession($session, $reason);

            return true;
        } catch (\Throwable $e) {
            $this->getLogService()->error(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK,
                '超时清理异常', [
                    'type' => $type,
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            return false;
        }
    }

    protected function getBiz(): Bfw
    {
        return Core::instance();
    }

    protected function getVoiceTalkService(): VoiceTalkService
    {
        return $this->getBiz()->service('Devices:VoiceTalkService');
    }

    protected function getLogService(): SystemLogService
    {
        return $this->getBiz()->service('SystemLog:SystemLogService');
    }
}
