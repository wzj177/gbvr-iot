<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Enums\DeviceStatusEnum;
use CoreW\Business\Devices\Enums\MediaServerType;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Devices\Traits\GB28181StreamTrait;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Business\MediaServer\Service\MediaServerService;
use support\Request;

/**
 * GB28181 视频流控制器 - 管理后台
 */
class GB28181StreamController extends BaseController
{
    use GB28181StreamTrait;

    /**
     * 开始实时视频
     */
    public function startLive(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少参数device_id或channel_id', 400);
        }

        try {
            // 验证设备和通道
            ['device' => $device, 'channel' => $channel] = $this->validateDeviceAndChannel($deviceId, $channelId);

            // 执行开始直播核心逻辑
            $result = $this->startLiveVideoCore($device, $channel);
            $playUrls = $this->getPlayUrlsCore($channel);

            return $this->createSuccessJsonResponse([
                ...$result,
                'play_urls' => $playUrls,
                'message' => '实时视频开始',
            ]);
        } catch (\Exception $e) {
            return $this->handleStreamException($e);
        }
    }

    /**
     * 停止实时视频
     */
    public function stopLive(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少参数device_id或channel_id', 400);
        }

        try {
            $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);

            if (!$channel) {
                return $this->createErrorJsonResponse('通道不存在', 404);
            }

            $this->stopLiveVideoCore($channel);

            return $this->createSuccessJsonResponse([
                'message' => '实时视频已停止',
            ]);
        } catch (\Exception $e) {
            return $this->handleStreamException($e);
        }
    }

    /**
     * 获取播放地址
     */
    public function getPlayUrls(Request $request)
    {
        $deviceId = $request->get('device_id');
        $channelId = $request->get('channel_id');

        if (!$deviceId || !$channelId) {
            return $this->createErrorJsonResponse('缺少参数device_id或channel_id', 400);
        }

        try {
            $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);

            if (!$channel) {
                return $this->createErrorJsonResponse('通道不存在', 404);
            }

            if ($channel['status'] !== 'streaming') {
                return $this->createErrorJsonResponse('通道未在推流', 400);
            }

            $playUrls = $this->getPlayUrlsCore($channel);

            return $this->createSuccessJsonResponse([
                'stream_id' => $channel['stream_id'],
                'play_urls' => $playUrls,
            ]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage(), $e->getCode() ?? 500);
        }
    }



    /**
     * 开始录像回放
     */
    public function startPlayback(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $startTime = $request->post('start_time'); // 2024-01-01T00:00:00
        $endTime = $request->post('end_time');

        if (!$deviceId || !$channelId || !$startTime || !$endTime) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        }

        // 验证时间格式
        if (!$this->validateTimeFormat($startTime) || !$this->validateTimeFormat($endTime)) {
            return $this->createErrorJsonResponse('时间格式错误，应为: 2024-01-01T00:00:00', 400);
        }

        try {
            $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);

            if (!$device) {
                return $this->createErrorJsonResponse('设备不存在', 404);
            }

            $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);
            if (!$channel) {
                return $this->createErrorJsonResponse('通道不存在', 404);
            }

            $result = $this->startPlaybackCore($device, $channel, $startTime, $endTime);

            $playUrls = $this->getPlayUrlsCore($channel, $result['stream_id']);

            return $this->createSuccessJsonResponse([
                ...$result,
                'play_urls' => $playUrls,
                'message' => '录像回放开始',
            ]);
        } catch (\Exception $e) {
            return $this->handleStreamException($e);
        }
    }

    public function stopPlayback(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $streamId = $request->post('stream_id');
        if (!$deviceId || !$channelId || !$streamId) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        };
        try {
            $result = $this->stopPlaybackCore($deviceId, $channelId, $streamId);
        } catch (\Exception $e) {
            return $this->handleStreamException($e);
        }
    }

    /**
     * @return Gb28181Service
     */
    protected function getGb28181Service(): Gb28181Service
    {
        return $this->getBiz()->offsetGet('gb28181_service');
    }

    /**
     * @return DeviceService
     */
    protected function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }



    /**
     * 处理流媒体异常
     */
    protected function handleStreamException(\Exception $e): \support\Response
    {
        $code = $e->getCode() ?: 500;
        $message = $e->getMessage();

        // 根据异常类型返回不同的错误信息
        if ($e instanceof \InvalidArgumentException) {
            return $this->createErrorJsonResponse($message, is_numeric($code) ? (int)$code : 400);
        }

        return $this->createErrorJsonResponse($message, is_numeric($code) ? (int)$code : 500);
    }
}
