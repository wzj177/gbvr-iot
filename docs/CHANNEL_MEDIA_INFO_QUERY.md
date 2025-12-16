# 通道音视频信息获取方案

## 需求说明

在播放实时预览、回放时，需要获取通道的音视频参数（音频编码、视频编码、分辨率、帧率等），并通过 Hook 回调更新 stream 表，供前端异步接口查询。

## 信息来源分析

### GB28181 协议限制

⚠️ **GB28181 没有专门的查询命令**用于获取通道的实时音视频参数。

可用信息来源：

| 信息来源 | 时机 | 音频编码 | 视频编码 | 分辨率 | 帧率 | 码率 |
|---------|------|---------|---------|-------|------|------|
| SDP 协商 | INVITE 响应 | ✅ | ✅ | ❌ | ❌ | ❌ |
| ZLM 流信息 | 流建立后 | ✅ | ✅ | ✅ | ✅ | ✅ |
| MediaStatus 通知 | 设备主动推送 | ❌ | ❌ | ✅ | ✅ | ✅ |

## 推荐方案：混合获取策略

### 流程设计

```
1. INVITE 响应阶段
   ├─ 解析 SDP → 提取音频编码、视频编码
   └─ 保存到 stream 表

2. 流建立后（收到第一个 RTP 包或 10 秒后）
   ├─ 调用 ZLM getMediaInfo API
   ├─ 获取实际分辨率、帧率、码率
   ├─ 合并 SDP 信息
   └─ 更新 stream 表 + 触发 Hook 回调

3. 前端轮询
   └─ GET /api/streams/{stream_id}/media_info
```

### 数据流图

```
┌──────────────┐
│   设备发送    │
│  INVITE 200  │
│   (含 SDP)   │
└──────┬───────┘
       │
       ▼
┌─────────────────────────────┐
│  GB28181Handler             │
│  handleInviteResponse()     │
│  ├─ 解析 SDP                 │
│  ├─ 提取编码信息             │
│  └─ 保存 stream (初始信息)   │
└──────┬──────────────────────┘
       │
       ▼
┌─────────────────────────────┐
│  流建立检测                  │
│  (RTP 包到达 或 延时检测)    │
└──────┬──────────────────────┘
       │
       ▼
┌─────────────────────────────┐
│  Gb28181Service             │
│  queryChannelMediaInfo()    │
│  ├─ 调用 ZLM getMediaInfo   │
│  ├─ 合并 SDP 信息            │
│  └─ 更新 stream 表           │
└──────┬──────────────────────┘
       │
       ▼
┌─────────────────────────────┐
│  Hook 回调                   │
│  POST /api/hook/media_info  │
│  ├─ stream_id               │
│  ├─ video_codec             │
│  ├─ audio_codec             │
│  ├─ resolution              │
│  ├─ fps                     │
│  └─ bit_rate                │
└──────┬──────────────────────┘
       │
       ▼
┌─────────────────────────────┐
│  业务系统更新 stream 表      │
└─────────────────────────────┘
```

## 实现方案

### 1. Service 层新增方法

**文件**: `Gb28181Service.php`

```php
/**
 * 查询通道媒体信息
 * 
 * 混合获取策略：
 * 1. 从 ZLM 获取实时流信息（分辨率、帧率、码率）
 * 2. 从 stream 表获取 SDP 信息（音视频编码）
 * 3. 合并返回完整信息
 * 
 * @param string $streamId 流ID
 * @return array|null 媒体信息
 */
public function queryChannelMediaInfo(string $streamId): ?array
{
    // 1. 从数据库获取 stream 基本信息（包含 SDP 提取的编码信息）
    $stream = $this->getDeviceService()->getSessionByStreamId($streamId);
    if (!$stream) {
        return null;
    }
    
    // 2. 从 ZLM 获取实时流信息
    $zlmInfo = null;
    try {
        $schema = $stream['type'] === 'playback' ? 'rtmp' : 'rtmp';
        $zlmInfo = $this->getZlmClient()->getMediaInfo($schema, $streamId);
    } catch (\Exception $e) {
        // ZLM 查询失败，可能流还未建立
    }
    
    // 3. 合并信息
    $mediaInfo = [
        'stream_id' => $streamId,
        'device_id' => $stream['device_id'],
        'channel_id' => $stream['channel_id'],
        'type' => $stream['type'],
        
        // 从 SDP 获取的编码信息
        'video_codec' => $stream['video_codec'] ?? null,
        'audio_codec' => $stream['audio_codec'] ?? null,
        
        // 从 ZLM 获取的实时信息
        'online' => false,
        'resolution' => null,
        'width' => null,
        'height' => null,
        'fps' => null,
        'bit_rate' => null,
        'audio_sample_rate' => null,
        'audio_channels' => null,
    ];
    
    // 4. 如果 ZLM 有数据，更新实时信息
    if ($zlmInfo && $zlmInfo['code'] === 0 && $zlmInfo['online']) {
        $mediaInfo['online'] = true;
        
        foreach ($zlmInfo['tracks'] ?? [] as $track) {
            if ($track['codec_type'] === 0) {
                // 视频轨道
                $mediaInfo['width'] = $track['width'] ?? null;
                $mediaInfo['height'] = $track['height'] ?? null;
                $mediaInfo['resolution'] = $mediaInfo['width'] && $mediaInfo['height'] 
                    ? "{$mediaInfo['width']}x{$mediaInfo['height']}" 
                    : null;
                $mediaInfo['fps'] = $track['fps'] ?? null;
                $mediaInfo['bit_rate'] = $track['bit_rate'] ?? null;
                
                // 更新视频编码（ZLM 的更准确）
                if (isset($track['codec_id'])) {
                    $mediaInfo['video_codec'] = $this->getCodecName($track['codec_id']);
                }
            } elseif ($track['codec_type'] === 1) {
                // 音频轨道
                $mediaInfo['audio_sample_rate'] = $track['sample_rate'] ?? null;
                $mediaInfo['audio_channels'] = $track['channels'] ?? null;
                
                // 更新音频编码
                if (isset($track['codec_id'])) {
                    $mediaInfo['audio_codec'] = $this->getCodecName($track['codec_id']);
                }
            }
        }
    }
    
    return $mediaInfo;
}

/**
 * 获取编码名称
 * 
 * @param int $codecId ZLM 编码ID
 * @return string
 */
private function getCodecName(int $codecId): string
{
    return match($codecId) {
        0 => 'H264',
        1 => 'H265',
        2 => 'AAC',
        3 => 'G711A',  // PCMA
        4 => 'G711U',  // PCMU
        7 => 'H264',
        8 => 'MPEG4',
        default => "Unknown($codecId)"
    };
}

/**
 * 自动查询并更新通道媒体信息
 * 
 * 在流建立后自动调用，更新数据库并触发 Hook
 * 
 * @param string $streamId 流ID
 * @return bool
 */
public function autoUpdateChannelMediaInfo(string $streamId): bool
{
    $mediaInfo = $this->queryChannelMediaInfo($streamId);
    if (!$mediaInfo || !$mediaInfo['online']) {
        return false;
    }
    
    // 更新数据库
    $this->getDeviceService()->updateStreamMediaInfo($streamId, $mediaInfo);
    
    // 触发 Hook 回调
    $this->triggerMediaInfoHook($streamId, $mediaInfo);
    
    return true;
}

/**
 * 触发媒体信息 Hook 回调
 * 
 * @param string $streamId 流ID
 * @param array $mediaInfo 媒体信息
 */
private function triggerMediaInfoHook(string $streamId, array $mediaInfo): void
{
    // 调用 Hook API
    $hookUrl = config('gb28181.hook_url') . '/media_info';
    
    try {
        $this->httpClient->post($hookUrl, [
            'json' => [
                'event' => 'channel_media_info',
                'stream_id' => $streamId,
                'device_id' => $mediaInfo['device_id'],
                'channel_id' => $mediaInfo['channel_id'],
                'type' => $mediaInfo['type'],
                'video_codec' => $mediaInfo['video_codec'],
                'audio_codec' => $mediaInfo['audio_codec'],
                'resolution' => $mediaInfo['resolution'],
                'width' => $mediaInfo['width'],
                'height' => $mediaInfo['height'],
                'fps' => $mediaInfo['fps'],
                'bit_rate' => $mediaInfo['bit_rate'],
                'audio_sample_rate' => $mediaInfo['audio_sample_rate'],
                'audio_channels' => $mediaInfo['audio_channels'],
                'timestamp' => time(),
            ],
            'timeout' => 5,
        ]);
    } catch (\Exception $e) {
        // Hook 调用失败，记录日志
        logger()->error("Media info hook failed: {$e->getMessage()}");
    }
}
```

### 2. Handler 层：SDP 解析

**文件**: `GB28181Handler.php`

```php
/**
 * 处理 INVITE 响应（提取 SDP 信息）
 */
private function handleInviteResponse(\SipEvent $event): void
{
    $body = $event->getBody();
    if (empty($body)) {
        return;
    }
    
    // 解析 SDP
    $sdpInfo = $this->parseSdpMediaInfo($body);
    
    // 提取设备 SSRC
    $deviceSsrc = $sdpInfo['ssrc'] ?? null;
    
    // 查找会话（根据 Call-ID）
    $callId = $event->getCallId();
    $session = $this->deviceManager->findSessionByCallId($callId);
    
    if ($session) {
        // 更新会话信息
        $updateData = [
            'status' => 'active',
            'device_ssrc' => $deviceSsrc,
            'video_codec' => $sdpInfo['video_codec'] ?? null,
            'audio_codec' => $sdpInfo['audio_codec'] ?? null,
        ];
        
        $this->deviceService->updateSession($session['id'], $updateData);
        
        // 更新 ZLM SSRC
        if ($deviceSsrc) {
            $this->gb28181Service->updateRtpServerSsrc($session['stream_id'], $deviceSsrc);
        }
        
        // 延迟查询媒体信息（给流建立一些时间）
        $this->scheduleMediaInfoQuery($session['stream_id'], 10);
    }
}

/**
 * 解析 SDP 提取媒体信息
 * 
 * @param string $sdp SDP 内容
 * @return array
 */
private function parseSdpMediaInfo(string $sdp): array
{
    $info = [
        'ssrc' => null,
        'video_codec' => null,
        'audio_codec' => null,
    ];
    
    // 使用原生 SDP 解析器
    $parsed = \ExoSip::parseSdp($sdp);
    if (!$parsed) {
        return $info;
    }
    
    // 提取 SSRC (y= 字段)
    if (isset($parsed['ssrc'])) {
        $info['ssrc'] = $parsed['ssrc'];
    }
    
    // 提取视频编码
    foreach ($parsed['medias'] ?? [] as $media) {
        if ($media['type'] === 'video') {
            // 从 rtpmap 提取编码
            $attributes = $media['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                if (strpos($key, 'rtpmap:') === 0) {
                    // 格式: "rtpmap:96 PS/90000" 或 "rtpmap:98 H264/90000"
                    if (preg_match('/rtpmap:\d+\s+([A-Z0-9]+)\//', $value, $matches)) {
                        $info['video_codec'] = $matches[1];
                        break;
                    }
                }
            }
        } elseif ($media['type'] === 'audio') {
            // 提取音频编码
            $attributes = $media['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                if (strpos($key, 'rtpmap:') === 0) {
                    // 格式: "rtpmap:8 PCMA/8000"
                    if (preg_match('/rtpmap:\d+\s+([A-Z0-9]+)\//', $value, $matches)) {
                        $info['audio_codec'] = $matches[1];
                        break;
                    }
                }
            }
        }
    }
    
    return $info;
}

/**
 * 调度媒体信息查询任务
 * 
 * @param string $streamId 流ID
 * @param int $delaySeconds 延迟秒数
 */
private function scheduleMediaInfoQuery(string $streamId, int $delaySeconds): void
{
    // 方案1: 使用 Redis 延迟队列
    // Redis::zadd('gb28181:media_info_query', time() + $delaySeconds, $streamId);
    
    // 方案2: 直接在后台任务中处理
    // 这里简化处理，实际应该使用队列
    register_shutdown_function(function() use ($streamId, $delaySeconds) {
        sleep($delaySeconds);
        $this->gb28181Service->autoUpdateChannelMediaInfo($streamId);
    });
}
```

### 3. 数据库表结构

**streams 表新增字段**：

```sql
ALTER TABLE `streams` ADD COLUMN `video_codec` VARCHAR(20) NULL COMMENT '视频编码(H264/H265/PS)';
ALTER TABLE `streams` ADD COLUMN `audio_codec` VARCHAR(20) NULL COMMENT '音频编码(PCMA/PCMU/AAC)';
ALTER TABLE `streams` ADD COLUMN `resolution` VARCHAR(20) NULL COMMENT '分辨率(1920x1080)';
ALTER TABLE `streams` ADD COLUMN `width` INT NULL COMMENT '视频宽度';
ALTER TABLE `streams` ADD COLUMN `height` INT NULL COMMENT '视频高度';
ALTER TABLE `streams` ADD COLUMN `fps` INT NULL COMMENT '帧率';
ALTER TABLE `streams` ADD COLUMN `bit_rate` INT NULL COMMENT '码率(bps)';
ALTER TABLE `streams` ADD COLUMN `audio_sample_rate` INT NULL COMMENT '音频采样率';
ALTER TABLE `streams` ADD COLUMN `audio_channels` INT NULL COMMENT '音频声道数';
ALTER TABLE `streams` ADD COLUMN `media_info_updated_at` TIMESTAMP NULL COMMENT '媒体信息更新时间';
```

### 4. API 接口

**GET /api/streams/{stream_id}/media_info**

```php
/**
 * 获取流媒体信息
 * 
 * @param string $streamId
 * @return JsonResponse
 */
public function getStreamMediaInfo(string $streamId): JsonResponse
{
    $stream = Stream::where('stream_id', $streamId)->first();
    
    if (!$stream) {
        return response()->json(['error' => 'Stream not found'], 404);
    }
    
    return response()->json([
        'stream_id' => $stream->stream_id,
        'device_id' => $stream->device_id,
        'channel_id' => $stream->channel_id,
        'type' => $stream->type,
        'status' => $stream->status,
        
        // 媒体信息
        'video_codec' => $stream->video_codec,
        'audio_codec' => $stream->audio_codec,
        'resolution' => $stream->resolution,
        'width' => $stream->width,
        'height' => $stream->height,
        'fps' => $stream->fps,
        'bit_rate' => $stream->bit_rate,
        'audio_sample_rate' => $stream->audio_sample_rate,
        'audio_channels' => $stream->audio_channels,
        
        // 元信息
        'media_info_updated_at' => $stream->media_info_updated_at,
        'created_at' => $stream->created_at,
    ]);
}
```

**Hook 回调接口**

```php
/**
 * 接收媒体信息 Hook 回调
 * 
 * POST /api/hook/media_info
 */
public function handleMediaInfoHook(Request $request): JsonResponse
{
    $data = $request->validate([
        'stream_id' => 'required|string',
        'device_id' => 'required|string',
        'channel_id' => 'required|string',
        'video_codec' => 'nullable|string',
        'audio_codec' => 'nullable|string',
        'resolution' => 'nullable|string',
        'width' => 'nullable|integer',
        'height' => 'nullable|integer',
        'fps' => 'nullable|integer',
        'bit_rate' => 'nullable|integer',
        'audio_sample_rate' => 'nullable|integer',
        'audio_channels' => 'nullable|integer',
    ]);
    
    // 更新 stream 表
    Stream::where('stream_id', $data['stream_id'])->update([
        'video_codec' => $data['video_codec'],
        'audio_codec' => $data['audio_codec'],
        'resolution' => $data['resolution'],
        'width' => $data['width'],
        'height' => $data['height'],
        'fps' => $data['fps'],
        'bit_rate' => $data['bit_rate'],
        'audio_sample_rate' => $data['audio_sample_rate'],
        'audio_channels' => $data['audio_channels'],
        'media_info_updated_at' => now(),
    ]);
    
    return response()->json(['success' => true]);
}
```

## 使用流程

### 后端流程

```php
// 1. 开始播放
$session = $gb28181Service->createLiveSession($deviceId, $channelId, $tcpMode);
$gb28181Service->startLiveVideo(...);

// 2. INVITE 响应时（Handler 自动处理）
// - 解析 SDP
// - 保存 video_codec, audio_codec
// - 调度媒体信息查询任务（延迟 10 秒）

// 3. 延迟查询执行（后台任务）
// - 调用 ZLM getMediaInfo
// - 合并 SDP 信息
// - 更新数据库
// - 触发 Hook 回调

// 4. Hook 回调（API 接收）
// - 更新 streams 表的媒体信息字段
```

### 前端流程

```javascript
// 1. 开始播放
const response = await fetch('/api/streams/start', {
    method: 'POST',
    body: JSON.stringify({ device_id, channel_id })
});
const { stream_id, play_url } = await response.json();

// 2. 加载播放器
player.load(play_url);

// 3. 轮询获取媒体信息（直到获取到完整信息）
const pollMediaInfo = async () => {
    const response = await fetch(`/api/streams/${stream_id}/media_info`);
    const mediaInfo = await response.json();
    
    if (mediaInfo.resolution && mediaInfo.fps) {
        // 信息已更新，显示在界面
        updateUI({
            codec: `${mediaInfo.video_codec} / ${mediaInfo.audio_codec}`,
            resolution: mediaInfo.resolution,
            fps: `${mediaInfo.fps} fps`,
            bitrate: `${(mediaInfo.bit_rate / 1000).toFixed(0)} kbps`
        });
    } else {
        // 信息未就绪，继续轮询
        setTimeout(pollMediaInfo, 2000);
    }
};

// 延迟 3 秒后开始轮询（给流建立时间）
setTimeout(pollMediaInfo, 3000);
```

## 数据示例

### Hook 回调数据

```json
{
    "event": "channel_media_info",
    "stream_id": "01A23456",
    "device_id": "34020000001320000001",
    "channel_id": "34020000001320000001",
    "type": "live",
    "video_codec": "H264",
    "audio_codec": "PCMA",
    "resolution": "1920x1080",
    "width": 1920,
    "height": 1080,
    "fps": 25,
    "bit_rate": 2048000,
    "audio_sample_rate": 8000,
    "audio_channels": 1,
    "timestamp": 1705288200
}
```

### API 响应数据

```json
{
    "stream_id": "01A23456",
    "device_id": "34020000001320000001",
    "channel_id": "34020000001320000001",
    "type": "live",
    "status": "active",
    "video_codec": "H264",
    "audio_codec": "PCMA",
    "resolution": "1920x1080",
    "width": 1920,
    "height": 1080,
    "fps": 25,
    "bit_rate": 2048000,
    "audio_sample_rate": 8000,
    "audio_channels": 1,
    "media_info_updated_at": "2024-01-15 10:35:00",
    "created_at": "2024-01-15 10:34:50"
}
```

## 注意事项

### 1. 时机选择

- **SDP 解析**：INVITE 200 OK 响应时立即进行
- **ZLM 查询**：流建立后延迟 5-10 秒（给设备推流时间）
- **前端轮询**：播放开始后 3 秒开始，每 2 秒一次，最多 10 次

### 2. 容错处理

- SDP 可能不包含所有编码信息（使用默认值）
- ZLM 查询可能失败（流未建立或网络问题）
- 前端需要处理信息不完整的情况

### 3. 性能优化

- 使用 Redis 缓存媒体信息（避免频繁查询）
- 使用队列处理延迟查询任务
- 前端使用 WebSocket 推送更新（代替轮询）

### 4. MediaStatus 通知（可选）

如果设备支持 GB28181-2022 的 MediaStatus Keepalive 通知：

```xml
<Notify>
    <CmdType>MediaStatus</CmdType>
    <NotifyType>Keepalive</NotifyType>
    <SSRC>0123456789</SSRC>
    <BitRate>2048</BitRate>
    <FrameRate>25</FrameRate>
    <Resolution>1920x1080</Resolution>
    <PacketLoss>0.1</PacketLoss>
</Notify>
```

可以在 `handleMediaStatusReport()` 中更新实时信息，不需要查询 ZLM。

## 总结

✅ **推荐方案**：
1. INVITE 响应时从 SDP 提取编码信息
2. 流建立后从 ZLM 获取分辨率、帧率、码率
3. 合并信息后更新数据库并触发 Hook
4. 前端通过 API 轮询获取完整信息

✅ **优点**：
- 信息准确（来自实际流数据）
- 不依赖设备支持特殊协议
- 前后端解耦（异步更新）

⚠️ **注意**：
- 需要延迟查询（给流建立时间）
- 前端需要轮询或 WebSocket 推送
- 需要处理信息不完整的情况
