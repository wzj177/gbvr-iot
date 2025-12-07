<?php

namespace app\api\v2\controller;

use app\api\BaseController;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use support\Log;
use support\Redis;
use support\Request;

/**
 * GB28181 视频流控制器
 */
class GB28181StreamController extends BaseController
{
    private ZLMClient $zlmClient;
    
    public function __construct()
    {
        parent::__construct();
        
        // 初始化ZLM客户端
        $this->zlmClient = new ZLMClient([
            'host' => config('zlm.host', '127.0.0.1'),
            'port' => config('zlm.port', 80),
            'secret' => config('zlm.secret', ''),
            'debug' => config('zlm.debug', false),
        ]);
    }
    
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
        $device = Db::table('devices')
            ->where('device_id', $deviceId)
            ->first();
        
        if (!$device) {
            return $this->createErrorJsonResponse('设备不存在', 404);
        }
        
        if ($device->status !== 'online') {
            return $this->createErrorJsonResponse('设备离线', 400);
        }
        
        // 2. 获取或创建通道记录
        $channel = Db::table('device_channels')
            ->where('device_id', $deviceId)
            ->where('channel_id', $channelId)
            ->first();
        
        if (!$channel) {
            // 创建通道
            $ssrc = $this->generateUniqueSsrc();
            $streamId = $this->generateStreamId($deviceId, $channelId);
            
            $channelData = [
                'device_id' => $deviceId,
                'channel_id' => $channelId,
                'ssrc' => $ssrc,
                'stream_id' => $streamId,
                'status' => 'offline',
                'enabled' => true,
                'media_server_id' => 'default',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            
            Db::table('device_channels')->insert($channelData);
            $channel = (object)$channelData;
        } else if (!$channel->ssrc) {
            // 补充SSRC
            $ssrc = $this->generateUniqueSsrc();
            Db::table('device_channels')
                ->where('id', $channel->id)
                ->update(['ssrc' => $ssrc]);
            $channel->ssrc = $ssrc;
        }
        
        // 3. 调用ZLM分配端口
        $tcpMode = config('zlm.default_tcp_mode', 1); // 0=UDP, 1=TCP被动(推荐), 2=TCP主动
        $zlmResult = $this->zlmClient->openRtpServer(
            $channel->stream_id,
            0,  // 自动分配端口
            $tcpMode,  // 传输模式
            true,   // 录制MP4
            $channel->ssrc
        );
        
        if (!$zlmResult || $zlmResult['code'] !== 0) {
            return $this->createErrorJsonResponse('ZLM端口分配失败: ' . ($zlmResult['msg'] ?? 'Unknown error'), 500);
        }
        
        $zlmPort = $zlmResult['port'];
        
        // 4. 发送命令到信令网关
        $requestId = uniqid('live_');
        Redis::publish('gb28181:commands', json_encode([
            'action' => 'start_live_video',
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'request_id' => $requestId,
            'params' => [
                'ssrc' => $channel->ssrc,
                'zlm_port' => $zlmPort,
                'tcp_mode' => $tcpMode, // 传递给信令网关
                'stream_id' => $channel->stream_id,
            ],
            'timestamp' => time(),
        ]));
        
        // 5. 更新通道状态
        Db::table('device_channels')
            ->where('id', $channel->id)
            ->update([
                'status' => 'streaming',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        Log::channel('sip')->info('Start live video command sent', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'ssrc' => $channel->ssrc,
            'zlm_port' => $zlmPort,
            'tcp_mode' => $tcpMode,
            'stream_id' => $channel->stream_id,
        ]);
        
        return $this->createSuccessJsonResponse([
            'request_id' => $requestId,
            'stream_id' => $channel->stream_id,
            'ssrc' => $channel->ssrc,
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
        
        $channel = Db::table('device_channels')
            ->where('device_id', $deviceId)
            ->where('channel_id', $channelId)
            ->first();
        
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }
        
        // 1. 发送BYE到信令网关
        $requestId = uniqid('stop_');
        Redis::publish('gb28181:commands', json_encode([
            'action' => 'stop_live_video',
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'request_id' => $requestId,
            'timestamp' => time(),
        ]));
        
        // 2. 关闭ZLM端口
        if ($channel->stream_id) {
            $this->zlmClient->closeRtpServer($channel->stream_id);
        }
        
        // 3. 更新通道状态
        Db::table('device_channels')
            ->where('id', $channel->id)
            ->update([
                'status' => 'offline',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        Log::channel('sip')->info('Stop live video command sent', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
        ]);
        
        return $this->createSuccessJsonResponse([
            'request_id' => $requestId,
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
        
        $channel = Db::table('device_channels')
            ->where('device_id', $deviceId)
            ->where('channel_id', $channelId)
            ->first();
        
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }
        
        if ($channel->status !== 'streaming') {
            return $this->createErrorJsonResponse('通道未在推流', 400);
        }
        
        // 获取播放地址
        $playUrls = $this->zlmClient->getPlayUrls($channel->stream_id);
        
        return $this->createSuccessJsonResponse([
            'stream_id' => $channel->stream_id,
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
        $channel = Db::table('device_channels')
            ->where('device_id', $deviceId)
            ->where('channel_id', $channelId)
            ->first();
        
        if (!$channel) {
            return $this->createErrorJsonResponse('通道不存在', 404);
        }
        
        // 为回放生成独立的Stream ID
        $playbackStreamId = $this->generateStreamId($deviceId, $channelId, 'playback_' . time());
        $playbackSsrc = $this->generateUniqueSsrc();
        
        // 调用ZLM分配端口
        $zlmResult = $this->zlmClient->openRtpServer(
            $playbackStreamId,
            0,
            false,
            true,
            $playbackSsrc
        );
        
        if (!$zlmResult || $zlmResult['code'] !== 0) {
            return $this->createErrorJsonResponse('ZLM端口分配失败', 500);
        }
        
        // 发送命令到信令网关
        $requestId = uniqid('playback_');
        // use sdk
//        Redis::publish('gb28181:commands', json_encode([
//            'action' => 'start_playback',
//            'device_id' => $deviceId,
//            'channel_id' => $channelId,
//            'request_id' => $requestId,
//            'params' => [
//                'ssrc' => $playbackSsrc,
//                'zlm_port' => $zlmResult['port'],
//                'stream_id' => $playbackStreamId,
//                'start_time' => $startTime,
//                'end_time' => $endTime,
//            ],
//            'timestamp' => time(),
//        ]));
        $this->getBiz()->offsetGet('gb28181_gateway_sdk')->sendCommand($deviceId, 'start_playback', [
            'channel_id' => $channelId,
            'request_id' => $requestId,
            'ssrc' => $playbackSsrc,
            'zlm_port' => $zlmResult['port'],
            'stream_id' => $playbackStreamId,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'timestamp' => time(),
        ]);
        Log::channel('sip')->info('Start playback command sent', [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ]);
        
        return $this->createSuccessJsonResponse([
            'request_id' => $requestId,
            'stream_id' => $playbackStreamId,
            'ssrc' => $playbackSsrc,
            'zlm_port' => $zlmResult['port'],
            'play_urls' => $this->zlmClient->getPlayUrls($playbackStreamId),
        ]);
    }
    
    /**
     * PTZ控制
     */
    public function ptzControl(Request $request)
    {
        $deviceId = $request->post('device_id');
        $channelId = $request->post('channel_id');
        $command = $request->post('command'); // up, down, left, right, zoom_in, zoom_out, stop
        $speed = $request->post('speed', 5); // 1-255
        
        if (!$deviceId || !$channelId || !$command) {
            return $this->createErrorJsonResponse('缺少必要参数', 400);
        }
        
        // 发送命令到信令网关
        $requestId = uniqid('ptz_');
        Redis::publish('gb28181:commands', json_encode([
            'action' => 'ptz_control',
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'request_id' => $requestId,
            'params' => [
                'command' => $command,
                'speed' => $speed,
            ],
            'timestamp' => time(),
        ]));
        
        return $this->createSuccessJsonResponse([
            'request_id' => $requestId,
            'message' => 'PTZ命令已发送',
        ]);
    }
    
    /**
     * 生成唯一SSRC
     */
    private function generateUniqueSsrc(): string
    {
        do {
            $ssrc = str_pad((string)rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
        } while (Db::table('device_channels')->where('ssrc', $ssrc)->exists());
        
        return $ssrc;
    }
    
    /**
     * 生成Stream ID
     */
    private function generateStreamId(string $deviceId, string $channelId, ?string $suffix = null): string
    {
        $streamId = "{$deviceId}_{$channelId}";
        if ($suffix) {
            $streamId .= "_{$suffix}";
        }
        return $streamId;
    }
    
    /**
     * 验证时间格式
     */
    private function validateTimeFormat(string $time): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $time) === 1;
    }
}
