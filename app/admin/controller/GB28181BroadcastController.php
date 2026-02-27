<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Service\VoiceTalkService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\MediaServer\Service\MediaServerService;
use CoreW\Business\SystemLog\LogEnum;
use support\Request;
use support\Log;

/**
 * GB28181 语音对讲/广播控制器
 * 
 * 语音对讲与视频流不同：
 * - 视频流：服务端提供 PLAY URL（客户端拉流）
 * - 语音对讲：服务端提供 PUSH URL（客户端推流到设备）
 */
class GB28181BroadcastController extends BaseController
{
    /**
     * 开始语音对讲/广播
     * 
     * POST /admin/gb28181/broadcast/start
     * 
     * @param Request $request
     *   - device_id: string 设备国标ID
     *   - channel_id: string 通道国标ID
     *   - mode: string talk(对讲)|broadcast(广播)，默认talk
     * 
     * @return \support\Response
     *   - push_url: string 推流地址（WebRTC/RTMP）
     *   - session_id: string 会话ID
     */
    public function start(Request $request): \support\Response
    {
        try {
            $deviceId = $request->post('device_id');
            $channelId = $request->post('channel_id');
            $mode = $request->post('mode', 'broadcast'); // talk
            if (empty($deviceId)) {
                return $this->createErrorJsonResponse('设备ID不能为空');
            }

            if (empty($channelId)) {
                return $this->createErrorJsonResponse('通道ID不能为空');
            }

            if (!in_array($mode, ['talk', 'broadcast'])) {
                return $this->createErrorJsonResponse('mode 必须是 talk 或 broadcast');
            }

            //  调用 VoiceTalkService 准备语音对讲
            $result = $this->getVoiceTalkService()->prepareTalk($deviceId, $channelId, $mode);

            $this->getLogService()->info(LogEnum::MODULE_GB28181, LogEnum::ACTION_VOICE_TALK, '语音对讲已启动', [
                'device_id' => $deviceId,
                'channel_id' => $channelId,
                'mode' => $mode,
                'session_id' => $result['session_id'] ?? null,
                'stream_id' => $result['stream_id'] ?? null,
            ]);

            return $this->createSuccessJsonResponse($result, '语音对讲已启动');

        } catch (\Throwable $e) {
            return $this->handleException($e, '启动语音对讲');
        }
    }

    /**
     * 停止语音对讲/广播
     */
    public function stop(Request $request): \support\Response
    {
        try {
            $sessionId = $request->post('session_id');

            //  调用 VoiceTalkService 停止语音对讲
            $result = $this->getVoiceTalkService()->stopVoiceTalk($sessionId);

            if (!$result) {
                return $this->createErrorJsonResponse('停止语音对讲失败');
            }

            return $this->createSuccessJsonResponse([], '语音对讲已停止');

        } catch (\Throwable $e) {
            return $this->handleException($e, '停止语音对讲');
        }
    }


    /**
     * 获取 VoiceTalkService
     */
    protected function getVoiceTalkService(): VoiceTalkService
    {
        return $this->createService('Devices:VoiceTalkService');
    }


    /**
     * 获取 Gb28181Service 实例
     * @return Gb28181Service
     */
    protected function getGb28181Service(): Gb28181Service
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }

    /**
     * 获取 DeviceService 实例
     * @return DeviceService
     */
    protected function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }

    /**
     * 获取 MediaServerService 实例
     * @return MediaServerService
     */
    protected function getMediaServerService(): MediaServerService
    {
        return $this->createService('MediaServer:MediaServerService');
    }

    /**
     * 统一异常处理
     */
    protected function handleException(\Throwable $e, string $action): \support\Response
    {
        Log::error("{$action}失败: " . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);

        return $this->createErrorJsonResponse("{$action}失败: " . $e->getMessage());
    }
}
