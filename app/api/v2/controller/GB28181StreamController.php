<?php

namespace app\api\v2\controller;

use app\api\BaseController;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\GB\Gb28181Service;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Log;
use support\Redis;
use support\Request;

/**
 * GB28181 视频流控制器
 */
class GB28181StreamController extends BaseController
{
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
        
        // 1. 检查设备是否在线
        $device = $this->getDeviceService()->getDeviceByDeviceId($deviceId);
        
        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }
        
        if ($device['status'] !== 'online') {
            return $this->createErrorJsonResponse('设备离线', 400);
        }
        
        // 2. 获取或创建通道记录
        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);
        
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }
        
        // 3. 创建直播会话
        $tcpMode = config('zlm.default_tcp_mode', 1); // 0=UDP, 1=TCP被动(推荐), 2=TCP主动
        try {
            $sessionResult = $this->getGb28181Service()->createLiveSession(
                $deviceId,
                $channelId,
                $tcpMode
            );
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('创建直播会话失败: ' . $e->getMessage(), 500);
        }
        
        if (!$sessionResult) {
            return $this->createErrorJsonResponse('创建直播会话失败', 500);
        }
        
        $zlmPort = $sessionResult['zlm_port'];
        $ssrc = $sessionResult['ssrc'];
        $streamId = $sessionResult['stream_id'];
        
        // 4. 发送命令到信令网关
        try {
            $result = $this->getGb28181Service()->startLiveVideo(
                $deviceId,
                $channelId,
                $ssrc,
                $zlmPort,
                $tcpMode, // 传递给信令网关
                $streamId
            );
            
            if (!$result) {
                // 如果发送失败，关闭已分配的端口
                $this->getGb28181Service()->closeRtpServer($streamId);
                return $this->createErrorJsonResponse('发送实时视频请求失败', 500);
            }
        } catch (\Exception $e) {
            // 如果发生异常，关闭已分配的端口
            $this->getGb28181Service()->closeRtpServer($streamId);
            return $this->createErrorJsonResponse('发送实时视频请求异常: ' . $e->getMessage(), 500);
        }
        
        // 5. 更新通道状态
        $this->getDeviceService()->updateChannel($channel['id'], [
            'status' => 'streaming',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Log::channel('sip')->info('Start live video command sent', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'ssrc' => $ssrc,
            'zlm_port' => $zlmPort,
            'tcp_mode' => $tcpMode,
            'stream_id' => $streamId,
        ]);
        
        return $this->createSuccessJsonResponse([
            'stream_id' => $streamId,
            'ssrc' => $ssrc,
            'zlm_port' => $zlmPort,
            'message' => 'INVITE命令已发送，请等待设备响应',
        ]);
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
        
        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);
        
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }
        
        // 1. 发送BYE到信令网关
        try {
            $result = $this->getGb28181Service()->stopLiveVideo($deviceId, $channelId);
            
            if (!$result) {
                return $this->createErrorJsonResponse('发送停止实时视频请求失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('发送停止实时视频请求异常: ' . $e->getMessage(), 500);
        }
        
        // 2. 关闭ZLM端口
        if (isset($channel['stream_id']) && $channel['stream_id']) {
            try {
                $this->getGb28181Service()->closeRtpServer($channel['stream_id']);
            } catch (\Exception $e) {
                Log::channel('sip')->warning('Close RTP server failed', [
                    'stream_id' => $channel['stream_id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // 3. 更新通道状态
        $this->getDeviceService()->updateChannel($channel['id'], [
            'status' => 'offline',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        
        Log::channel('sip')->info('Stop live video command sent', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
        ]);
        
        return $this->createSuccessJsonResponse([
            'message' => 'BYE命令已发送',
        ]);
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
        
        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);
        
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }
        
        if ($channel['status'] !== 'streaming') {
            return $this->createErrorJsonResponse('通道未在推流', 400);
        }
        
        // 获取播放地址
        try {
            $playUrls = $this->getGb28181Service()->getPlayUrls('rtp', $channel['stream_id']);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('获取播放地址失败: ' . $e->getMessage(), 500);
        }
        
        return $this->createSuccessJsonResponse([
            'stream_id' => $channel['stream_id'],
            'play_urls' => $playUrls,
        ]);
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
        
        // 获取通道
        $channel = $this->getDeviceService()->getChannelByDeviceAndChannel($deviceId, $channelId);
        
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }
        
        // 创建回放会话
        try {
            $sessionResult = $this->getGb28181Service()->createPlaybackSession(
                $deviceId,
                $channelId,
                $startTime,
                $endTime,
                1 // tcpMode
            );
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('创建回放会话失败: ' . $e->getMessage(), 500);
        }
        
        if (!$sessionResult) {
            return $this->createErrorJsonResponse('创建回放会话失败', 500);
        }
        
        $playbackStreamId = $sessionResult['stream_id'];
        $playbackSsrc = $sessionResult['ssrc'];
        $zlmPort = $sessionResult['zlm_port'];
        
        // 发送命令到信令网关
        try {
            $result = $this->getGb28181Service()->startPlayback(
                $deviceId,
                $channelId,
                $startTime,
                $endTime,
                $playbackSsrc,
                $zlmPort,
                1, // tcpMode
                $playbackStreamId
            );
            
            if (!$result) {
                // 如果发送失败，关闭已分配的端口
                $this->getGb28181Service()->closeRtpServer($playbackStreamId);
                return $this->createErrorJsonResponse('发送回放请求失败', 500);
            }
        } catch (\Exception $e) {
            // 如果发生异常，关闭已分配的端口
            $this->getGb28181Service()->closeRtpServer($playbackStreamId);
            return $this->createErrorJsonResponse('发送回放请求异常: ' . $e->getMessage(), 500);
        }
        
        try {
            $playUrls = $this->getGb28181Service()->getPlayUrls('rtp', $playbackStreamId);
        } catch (\Exception $e) {
            $playUrls = [];
        }
        
        Log::channel('sip')->info('Start playback command sent', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
        
        return $this->createSuccessJsonResponse([
            'stream_id' => $playbackStreamId,
            'ssrc' => $playbackSsrc,
            'zlm_port' => $zlmPort,
            'play_urls' => $playUrls,
        ]);
    }
    

    
    /**
     * @return Gb28181Service
     */
    private function getGb28181Service(): Gb28181Service
    {
        return $this->createService('GB:Gb28181Service');
    }
    
    /**
     * @return DeviceService
     */
    private function getDeviceService(): DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }
    
    /**
     * 验证时间格式
     */
    private function validateTimeFormat(string $time): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $time) === 1;
    }
}