<?php

namespace app\admin\controller;

use support\Request;
use support\Db;
use support\Log;
use support\Redis;

/**
 * ZLMediaKit Hook 处理器
 * 处理 ZLM 的流事件通知（on_stream_arrive, on_stream_none_reader 等）
 * 
 * 配置 ZLM Hook：
 * hook.enable=1
 * hook.on_stream_changed=http://webman-ip:8787/api/admin/gb28181/hook/stream_changed
 * hook.admin_params=secret=your_secret_key
 */
class GB28181HookController
{
    /**
     * 流变化事件（ZLM推荐使用，统一处理流的到达/离开）
     * POST /admin/api/gb28181/hook/stream_changed
     * 
     * @param Request $request
     * @return array
     * 
     * ZLM请求体示例：
     * {
     *   "regist": true,  // true=流到达, false=流离开
     *   "schema": "rtc",
     *   "app": "talk",
     *   "stream": "34020000001320000001_34020000001320000001",
     *   "mediaServerId": "zlm_server_1",
     *   "originType": 3,  // 3=WebRTC推流
     *   "originTypeStr": "rtc_push",
     *   ...
     * }
     */
    public function streamChanged(Request $request): array
    {
        $data = $request->post();
        
        // 验证秘钥
        if (!$this->verifySecret($data['secret'] ?? '')) {
            return ['code' => -1, 'msg' => 'Invalid secret'];
        }
        
        $regist = $data['regist'] ?? false;
        $app = $data['app'] ?? '';
        $stream = $data['stream'] ?? '';
        $mediaServerId = $data['mediaServerId'] ?? '';
        
        Log::info('[ZLM Hook] stream_changed', [
            'regist' => $regist ? 'arrive' : 'leave',
            'app' => $app,
            'stream' => $stream,
            'originType' => $data['originTypeStr'] ?? ''
        ]);
        
        // 只处理 talk 应用的流
        if ($app === 'talk') {
            if ($regist) {
                // 流到达 - 触发向设备发送 INVITE
                $this->handleStreamArrive($stream, $mediaServerId, $data);
            } else {
                // 流离开 - 清理会话
                $this->handleStreamLeave($stream);
            }
        }
        
        return ['code' => 0, 'msg' => 'success'];
    }
    
    /**
     * 流到达事件处理（核心逻辑）
     * 
     * @param string $stream 流ID
     * @param string $mediaServerId ZLM服务器ID
     * @param array $hookData Hook完整数据
     */
    private function handleStreamArrive(string $stream, string $mediaServerId, array $hookData): void
    {
        try {
            // 1. 查询等待此流的会话
            $session = Db::table('gv_gb28181_voice_sessions')
                ->where('stream', $stream)
                ->where('status', 'waiting_stream')
                ->first();
            
            if (!$session) {
                Log::warning('[VoiceTalk] 未找到等待的会话', ['stream' => $stream]);
                return;
            }
            
            // 2. 更新会话状态为 stream_arrived
            Db::table('gv_gb28181_voice_sessions')
                ->where('id', $session->id)
                ->update([
                    'status' => 'stream_arrived',
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
            Log::info('[VoiceTalk] 前端推流到达，准备发送INVITE', [
                'session_id' => $session->session_id,
                'device_id' => $session->device_id,
                'channel_id' => $session->channel_id
            ]);
            
            // 3. 发布任务到 Redis，由 Worker 处理 SIP INVITE
            $this->publishSipInviteTask([
                'action' => 'voice_talk_invite',
                'session_id' => $session->session_id,
                'device_id' => $session->device_id,
                'channel_id' => $session->channel_id,
                'stream' => $stream,
                'media_server_id' => $mediaServerId
            ]);
            
        } catch (\Exception $e) {
            Log::error('[VoiceTalk] 处理流到达失败', [
                'error' => $e->getMessage(),
                'stream' => $stream
            ]);
        }
    }
    
    /**
     * 流离开事件处理
     * 
     * @param string $stream 流ID
     */
    private function handleStreamLeave(string $stream): void
    {
        try {
            // 查询活动会话
            $session = Db::table('gv_gb28181_voice_sessions')
                ->where('stream', $stream)
                ->whereIn('status', ['stream_arrived', 'inviting', 'established'])
                ->first();
            
            if (!$session) {
                return;
            }
            
            Log::info('[VoiceTalk] 前端推流断开', [
                'session_id' => $session->session_id,
                'device_id' => $session->device_id
            ]);
            
            // 发布 BYE 任务到 Redis
            $this->publishSipByeTask([
                'action' => 'voice_talk_bye',
                'session_id' => $session->session_id,
                'device_id' => $session->device_id,
                'call_id' => $session->call_id,
                'dialog_id' => $session->dialog_id
            ]);
            
            // 更新会话状态
            Db::table('gv_gb28181_voice_sessions')
                ->where('id', $session->id)
                ->update([
                    'status' => 'ended',
                    'ended_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
        } catch (\Exception $e) {
            Log::error('[VoiceTalk] 处理流离开失败', [
                'error' => $e->getMessage(),
                'stream' => $stream
            ]);
        }
    }
    
    /**
     * 发布 SIP INVITE 任务到 Redis
     * 
     * @param array $taskData 任务数据
     */
    private function publishSipInviteTask(array $taskData): void
    {
        $redis = Redis::connection();
        $redis->rpush('gb28181:sip:tasks', json_encode($taskData));
        
        Log::info('[VoiceTalk] 发布INVITE任务到Redis', [
            'session_id' => $taskData['session_id']
        ]);
    }
    
    /**
     * 发布 SIP BYE 任务到 Redis
     * 
     * @param array $taskData 任务数据
     */
    private function publishSipByeTask(array $taskData): void
    {
        $redis = Redis::connection();
        $redis->rpush('gb28181:sip:tasks', json_encode($taskData));
        
        Log::info('[VoiceTalk] 发布BYE任务到Redis', [
            'session_id' => $taskData['session_id']
        ]);
    }
    
    /**
     * 验证 Hook 秘钥
     * 
     * @param string $secret 请求中的秘钥
     * @return bool
     */
    private function verifySecret(string $secret): bool
    {
        $expectedSecret = config('gb28181.zlm.hook_secret', 'default_secret');
        return $secret === $expectedSecret;
    }
}
