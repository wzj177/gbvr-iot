<?php

namespace app\api\v2\controller;

use app\api\BaseController;
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
    private ZLMClient $zlmClient;
    
    public function __construct()
    {
        parent::__construct();
        
        $this->zlmClient = new ZLMClient([
            'host' => config('zlm.host', '127.0.0.1'),
            'port' => config('zlm.port', 80),
            'secret' => config('zlm.secret', ''),
            'debug' => config('zlm.debug', false),
        ]);
    }
    
    public function index(Request $request)
    {
        $scene = $request->post('scene');
        $body = $request->post('body', []);
        
        Log::channel('sip')->info('GBServer Hook Received', [
            'scene' => $scene,
            'body' => $body,
        ]);
        
        try {
            switch ($scene) {
                case 'register':
                    $this->handleRegister($body);
                    break;
                
                case 'update_heartbeat':
                    $this->handleHeartbeat($body);
                    break;
                
                case 'save_catalog':
                    $this->handleCatalog($body);
                    break;
                
                case 'media_ready':
                    $this->handleMediaReady($body);
                    break;
                
                case 'device_status':
                    $this->handleDeviceStatus($body);
                    break;
                
                case 'alarm':
                    $this->handleAlarm($body);
                    break;
                
                default:
                    Log::channel('sip')->warning('Unknown hook scene', ['scene' => $scene]);
            }
            
            return $this->createSuccessJsonResponse();
            
        } catch (\Exception $e) {
            Log::channel('sip')->error('Hook handler exception', [
                'scene' => $scene,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
        $fromUri = $body['from_uri'] ?? '';
        
        if (!$deviceId) {
            return;
        }
        
        // 检查设备是否存在
        $device = Db::table('devices')->where('device_id', $deviceId)->first();
        
        if ($device) {
            // 更新设备状态
            Db::table('devices')
                ->where('device_id', $deviceId)
                ->update([
                    'status' => 'online',
                    'registered_at' => date('Y-m-d H:i:s'),
                    'last_heartbeat_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
        } else {
            // 创建设备记录
            Db::table('devices')->insert([
                'device_id' => $deviceId,
                'status' => 'online',
                'registered_at' => date('Y-m-d H:i:s'),
                'last_heartbeat_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        
        Log::channel('sip')->info('Device registered', ['device_id' => $deviceId]);
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
        
        Db::table('devices')
            ->where('device_id', $deviceId)
            ->update([
                'last_heartbeat_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
    
    /**
     * 处理设备目录
     */
    private function handleCatalog(array $body): void
    {
        $deviceId = $body['device_id'] ?? '';
        $devices = $body['devices'] ?? [];
        
        if (!$deviceId || empty($devices)) {
            return;
        }
        
        foreach ($devices as $item) {
            $channelId = $item['DeviceID'] ?? '';
            $channelName = $item['Name'] ?? '';
            $manufacturer = $item['Manufacturer'] ?? '';
            $parentId = $item['ParentID'] ?? '';
            
            if (!$channelId) {
                continue;
            }
            
            // 检查通道是否存在
            $channel = Db::table('device_channels')
                ->where('device_id', $deviceId)
                ->where('channel_id', $channelId)
                ->first();
            
            $channelData = [
                'channel_name' => $channelName,
                'manufacturer' => $manufacturer,
                'parent_id' => $parentId,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            
            if ($channel) {
                // 更新通道
                Db::table('device_channels')
                    ->where('id', $channel->id)
                    ->update($channelData);
            } else {
                // 创建通道
                $channelData['device_id'] = $deviceId;
                $channelData['channel_id'] = $channelId;
                $channelData['ssrc'] = $this->generateUniqueSsrc();
                $channelData['stream_id'] = "{$deviceId}_{$channelId}";
                $channelData['status'] = 'offline';
                $channelData['enabled'] = false;
                $channelData['media_server_id'] = 'default';
                $channelData['created_at'] = date('Y-m-d H:i:s');
                
                Db::table('device_channels')->insert($channelData);
            }
        }
        
        Log::channel('sip')->info('Device catalog saved', [
            'device_id' => $deviceId,
            'count' => count($devices),
        ]);
    }
    
    /**
     * 处理媒体流就绪（收到设备200 OK，包含设备SSRC）
     */
    private function handleMediaReady(array $body): void
    {
        $callId = $body['call_id'] ?? '';
        $deviceSsrc = $body['device_ssrc'] ?? '';
        $sdp = $body['sdp'] ?? [];
        $playUrls = $body['play_urls'] ?? null;
        
        Log::channel('sip')->info('Media ready', [
            'call_id' => $callId,
            'device_ssrc' => $deviceSsrc,
            'has_play_urls' => !empty($playUrls),
        ]);
        
        // TODO: 通知ZLM更新设备SSRC
        // 从会话中查找stream_id，然后调用ZLM API更新
        // 由于会话管理在信令网关，这里可能需要通过其他方式获取stream_id
        // 或者在media_ready事件中包含stream_id
        
        // 暂时记录日志
        if ($deviceSsrc) {
            Log::channel('sip')->info('Device SSRC received, should update ZLM', [
                'device_ssrc' => $deviceSsrc,
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
        
        $status = ($online === 'ONLINE') ? 'online' : 'offline';
        
        Db::table('devices')
            ->where('device_id', $deviceId)
            ->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        
        Log::channel('sip')->info('Device status changed', [
            'device_id' => $deviceId,
            'status' => $status,
        ]);
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
     * 生成唯一SSRC
     */
    private function generateUniqueSsrc(): string
    {
        do {
            $ssrc = str_pad((string)rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
        } while (Db::table('device_channels')->where('ssrc', $ssrc)->exists());
        
        return $ssrc;
    }
}