# GB28181 信令网关 + API项目 集成指南

## 架构概览

```
┌─────────────────────────────────────────────────────────────┐
│                         GB28181设备                          │
│                      (IPC摄像机/NVR等)                        │
└──────────────────┬──────────────────────────────────────────┘
                   │ SIP/RTP协议
                   ↓
┌─────────────────────────────────────────────────────────────┐
│               信令网关 (php-exosip)                          │
│  - 纯SIP信令处理                                              │
│  - REGISTER/INVITE/BYE等协议交互                             │
│  - 推送Hook到API项目                                          │
│  - 接收Redis命令并执行                                        │
└──────────────────┬──────────────────────────────────────────┘
                   │ HTTP Hook / Redis Pub/Sub
                   ↓
┌─────────────────────────────────────────────────────────────┐
│                  API项目 (gbvr-iot)                          │
│  - 接收Hook事件（注册、心跳、目录等）                          │
│  - 提供HTTP API（设备管理、流控制）                           │
│  - 管理SSRC（数据库唯一性）                                   │
│  - 集成ZLMediaKit（RTP端口分配、流管理）                      │
│  - 发布Redis命令到网关                                        │
└──────────────────┬──────────────────────────────────────────┘
                   │ HTTP API
                   ↓
┌─────────────────────────────────────────────────────────────┐
│                  ZLMediaKit 流媒体服务器                      │
│  - RTP over TCP/UDP接收                                      │
│  - 转码输出：RTSP/RTMP/FLV/HLS/WebRTC                        │
│  - 端口范围：30000-40000                                     │
└─────────────────────────────────────────────────────────────┘
```

## 一、RTP传输模式详解

### 1.1 传输模式对比

GB28181支持三种RTP传输模式，理解它们的区别对于公网部署至关重要：

| 模式 | tcp_mode | NAT穿透 | 延迟 | 适用场景 | 端口映射需求 |
|------|----------|---------|------|----------|-------------|
| UDP | 0 | ❌困难 | 最低 | 局域网 | 需要映射整个端口段(30000-40000) |
| TCP被动 | 1 | ✅简单 | 中等 | **公网推荐** | 只需映射服务器监听端口 |
| TCP主动 | 2 | ❌困难 | 中等 | 局域网/特殊场景 | 需要映射每个设备端口 |

#### UDP模式 (tcp_mode=0)

**工作原理：**
```
服务器(ZLM)                    设备
  监听30000端口                 |
      |                        |
      |<===RTP UDP数据包=======|
      |   从设备推流            |
```

**特点：**
- ✅ 延迟最低（无TCP开销）
- ✅ 局域网最佳选择
- ❌ 易丢包
- ❌ **公网NAT穿透困难**

**公网问题：**
```bash
# UDP模式需要映射整个RTP端口段
iptables -t nat -A PREROUTING -p udp --dport 30000:40000 \
  -j DNAT --to-destination 192.168.1.100:30000-40000

# 问题：
# 1. 需要开放10000个UDP端口
# 2. 运营商可能限制大端口段
# 3. 对称NAT无法穿透
```

#### TCP被动模式 (tcp_mode=1) ⭐推荐公网使用

**工作原理：**
```
服务器(ZLM)                    设备
  监听30000端口                 |
      |                        |
      |<===设备主动TCP连接=====|
      |   (出站连接)            |
      |                        |
      |<===RTP over TCP========|
```

**特点：**
- ✅ **公网友好**：设备主动连接，单向NAT自动处理
- ✅ 可靠传输（TCP保证）
- ✅ 只需服务器端口映射
- ❌ 延迟稍高

**公网部署：**
```bash
# 只需开放服务器RTP端口段
firewall-cmd --add-port=30000-40000/tcp --permanent
firewall-cmd --reload

# 设备的出站连接会被NAT自动处理，无需额外配置
```

**使用示例：**
```php
// API发起直播请求
$redis->publish('gb28181:commands', json_encode([
    'command' => 'start_live_video',
    'device_id' => '34020000001320000001',
    'channel_id' => '34020000001320000002',
    'ssrc' => '1234567890',
    'zlm_port' => 35024,
    'tcp_mode' => 1,  // TCP被动模式
]));
```

#### TCP主动模式 (tcp_mode=2)

**工作原理：**
```
服务器(ZLM)                    设备
      |                   监听8000端口
      |                        |
      |===服务器主动TCP连接===>|
      |   (需要知道设备IP)      |
      |                        |
      |====RTP over TCP=======>|
```

**特点：**
- ✅ 设备无需主动连接能力
- ❌ **公网几乎不可用**（无法连接内网设备）
- ❌ 需要每个设备配置端口映射

**公网问题：**
```bash
# 需要为每个设备单独映射端口
iptables -t nat -A PREROUTING -p tcp --dport 18000 \
  -j DNAT --to-destination 192.168.1.200:8000

# 问题：设备数量多时难以管理
```

**适用场景：**
- 语音对讲（服务器推流给设备）
- 设备支持TCP主动模式（大华部分型号）

### 1.2 推荐部署方案

#### 场景A: 纯局域网
```php
// 使用UDP，延迟最低
'default_tcp_mode' => 0,
```

#### 场景B: 公网视频监控 ⭐推荐
```php
// 使用TCP被动，设备主动连接
'default_tcp_mode' => 1,

// 防火墙配置
firewall-cmd --add-port=30000-40000/tcp --permanent
```

#### 场景C: 公网语音对讲
```
方案1: TCP主动 + 设备端口映射（复杂）
方案2: WebRTC + 流媒体中继（推荐）

浏览器 --WebRTC--> ZLM --TCP被动--> 设备
```

## 二、环境准备

### 2.1 数据库初始化

```bash
# 执行数据库迁移
mysql -u root -p your_database < database/migrations/gb28181_tables.sql
```

创建的表：
- `devices`: 设备表（device_id唯一索引）
- `device_channels`: 通道表（ssrc唯一索引）
- `stream_sessions`: 流会话表（session_id唯一索引）

### 2.2 配置文件设置

**config/zlm.php**
```php
return [
    'host' => '127.0.0.1',           // ZLM HTTP API地址
    'port' => 80,                     // ZLM HTTP API端口
    'secret' => 'your_secret_key',    // ZLM密钥
    'rtp_port_start' => 30000,        // RTP端口起始
    'rtp_port_end' => 40000,          // RTP端口结束
    'default_tcp_mode' => 1,          // 默认TCP被动模式（公网推荐）
];
```

**config/redis.php**
```php
return [
    'default' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
    ],
];
```

### 2.3 启动服务

```bash
# 1. 启动ZLMediaKit
cd /path/to/ZLMediaKit
./MediaServer -d

# 2. 启动信令网关
cd /path/to/php-exosip/examples
php gb28181_server.php

# 3. 启动API服务
cd /path/to/gbvr-iot
php start.php start
```

## 三、核心流程

### 3.1 设备注册流程

```
设备 → [SIP REGISTER] → 信令网关
                          ↓
                    [Hook推送]
                          ↓
                    API项目接收
                          ↓
                  [存储到devices表]
                          ↓
                  [status='online']
```

**Hook数据示例：**
```http
POST /api/v2/gb/server/hock
Content-Type: application/x-www-form-urlencoded

scene=register
&body[device_id]=34020000001320000001
&body[from_uri]=sip:34020000001320000001@3402000000
```

**API处理逻辑：**
- 检查设备是否存在
- 存在则更新状态为online
- 不存在则创建新设备记录

### 2.2 心跳更新流程

```
设备 → [SIP MESSAGE(心跳)] → 信令网关
                               ↓
                         [Hook推送]
                               ↓
                         API项目接收
                               ↓
                   [更新last_heartbeat_at]
```

**Hook数据示例：**
```http
POST /api/v2/gb/server/hock

scene=update_heartbeat
&body[device_id]=34020000001320000001
```

### 2.3 设备目录查询流程

```
API → [POST /devices/{id}/catalog] → 生成Redis命令
                                       ↓
                              [Redis PUBLISH]
                                       ↓
                              信令网关订阅接收
                                       ↓
                          [发送SIP MESSAGE(Catalog)]
                                       ↓
                          设备返回XML目录
                                       ↓
                          [Hook推送目录数据]
                                       ↓
                          API保存到device_channels表
```

**API请求示例：**
```bash
curl -X POST http://localhost:8787/api/v2/gb28181/devices/34020000001320000001/catalog \
  -H "X-Auth-Token: your_token"
```

**Redis命令格式：**
```json
{
  "command": "query_catalog",
  "device_id": "34020000001320000001"
}
```

**Hook返回示例：**
```http
POST /api/v2/gb/server/hock

scene=save_catalog
&body[device_id]=34020000001320000001
&body[devices][0][DeviceID]=34020000001320000002
&body[devices][0][Name]=IPC-Camera-01
&body[devices][0][Manufacturer]=Hikvision
```

### 2.4 启动直播流程（完整）

#### 步骤1: API接收请求

```bash
curl -X POST http://localhost:8787/api/v2/gb28181/channels/start-live \
  -H "X-Auth-Token: your_token" \
  -H "Content-Type: application/json" \
  -d '{
    "device_id": "34020000001320000001",
    "channel_id": "34020000001320000002"
  }'
```

#### 步骤2: API生成SSRC并分配ZLM端口

```php
// 1. 查询通道记录
$channel = Db::table('device_channels')
    ->where('device_id', $deviceId)
    ->where('channel_id', $channelId)
    ->first();

// 2. 使用通道的SSRC（已在目录保存时生成）
$ssrc = $channel->ssrc; // 例如: "1234567890"

// 3. 调用ZLM打开RTP端口
$tcpMode = config('zlm.default_tcp_mode', 1); // 默认TCP被动模式
$zlmResult = $zlmClient->openRtpServer([
    'stream_id' => $channel->stream_id, // "34020000001320000001_34020000001320000002"
    'port' => 0, // 自动分配
    'tcp_mode' => $tcpMode, // 0=UDP, 1=TCP被动(推荐), 2=TCP主动
    'ssrc' => $ssrc,
]);

$zlmPort = $zlmResult['port']; // 例如: 35024
```

**tcp_mode参数说明：**
- `0` - UDP模式：延迟最低，局域网推荐，公网需要大量端口映射
- `1` - TCP被动模式：**公网推荐**，设备主动连接，只需服务器端口映射
- `2` - TCP主动模式：服务器连接设备，公网需要设备端口映射

#### 步骤3: 发布Redis命令到信令网关

```php
$redis = Redis::connection();
$redis->publish('gb28181:commands', json_encode([
    'command' => 'start_live_video',
    'device_id' => '34020000001320000001',
    'channel_id' => '34020000001320000002',
    'ssrc' => '1234567890',
    'zlm_port' => 35024,
    'tcp_mode' => $tcpMode, // 传递给信令网关
    'session_id' => 'uuid-12345',
]));
```

#### 步骤4: 信令网关处理命令

```php
// CommandDispatcher.php
public function handleStartLiveVideo($params)
{
    $deviceId = $params['device_id'];
    $channelId = $params['channel_id'];
    $ssrc = $params['ssrc'];
    $zlmPort = $params['zlm_port'];
    
    // 构建SDP（使用API提供的SSRC）
    $sdp = $this->buildInviteSdp($zlmPort, $ssrc);
    
    // 发送SIP INVITE
    $result = exosip_send_invite(
        $deviceId,
        $channelId,
        $sdp
    );
    
    return $result;
}

private function buildInviteSdp($port, $ssrc)
{
    return "v=0\r\n" .
           "o=- 0 0 IN IP4 192.168.1.100\r\n" .
           "s=Play\r\n" .
           "c=IN IP4 192.168.1.100\r\n" .
           "t=0 0\r\n" .
           "m=video {$port} RTP/AVP 96\r\n" .
           "a=rtpmap:96 PS/90000\r\n" .
           "y={$ssrc}\r\n"; // 关键：使用API生成的SSRC
}
```

#### 步骤5: 设备返回200 OK

```
设备 → [SIP 200 OK + SDP] → 信令网关
        包含设备SSRC
              ↓
        [解析设备SDP]
              ↓
        提取设备SSRC（例如：0987654321）
              ↓
        [Hook推送media_ready]
```

**Hook数据：**
```http
POST /api/v2/gb/server/hock

scene=media_ready
&body[call_id]=abc123def456
&body[device_ssrc]=0987654321
&body[sdp][connection]=192.168.1.200
&body[sdp][port]=8000
```

#### 步骤6: API更新ZLM的SSRC

```php
// GBServerHockController.php
private function handleMediaReady(array $body): void
{
    $callId = $body['call_id'];
    $deviceSsrc = $body['device_ssrc'];
    
    // 从会话表查询stream_id
    $session = Db::table('stream_sessions')
        ->where('call_id', $callId)
        ->first();
    
    if ($session && $deviceSsrc) {
        // 更新ZLM：告知设备实际使用的SSRC
        $this->zlmClient->updateRtpServerSsrc([
            'stream_id' => $session->stream_id,
            'ssrc' => $deviceSsrc,
        ]);
        
        Log::info('Updated ZLM with device SSRC', [
            'stream_id' => $session->stream_id,
            'device_ssrc' => $deviceSsrc,
        ]);
    }
}
```

#### 步骤7: 设备开始推流

```
设备 → [RTP Packets to 192.168.1.100:35024] → ZLMediaKit
              SSRC: 0987654321
                    ↓
              [接收RTP流]
                    ↓
              [转码输出多种格式]
                    ↓
        RTSP/RTMP/FLV/HLS/WebRTC
```

#### 步骤8: 客户端获取播放地址

```bash
curl -X GET "http://localhost:8787/api/v2/gb28181/channels/play-urls?stream_id=34020000001320000001_34020000001320000002" \
  -H "X-Auth-Token: your_token"
```

**响应：**
```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "rtsp": "rtsp://192.168.1.100/rtp/34020000001320000001_34020000001320000002",
    "rtmp": "rtmp://192.168.1.100/rtp/34020000001320000001_34020000001320000002",
    "flv": "http://192.168.1.100/rtp/34020000001320000001_34020000001320000002.live.flv",
    "hls": "http://192.168.1.100/rtp/34020000001320000001_34020000001320000002/hls.m3u8",
    "webrtc": "http://192.168.1.100/rtp/34020000001320000001_34020000001320000002"
  }
}
```

### 2.5 停止直播流程

```
API → [POST /channels/stop-live] → 查询会话
                                     ↓
                            [Redis PUBLISH]
                                     ↓
                            信令网关接收
                                     ↓
                            [发送SIP BYE]
                                     ↓
                            设备停止推流
                                     ↓
                            [关闭ZLM端口]
                                     ↓
                            [删除会话记录]
```

## 三、API接口清单

### 3.1 设备管理

| 接口 | 方法 | 路径 | 说明 |
|------|------|------|------|
| 设备列表 | GET | `/api/v2/gb28181/devices` | 分页查询设备 |
| 设备详情 | GET | `/api/v2/gb28181/devices/{id}` | 获取设备详细信息 |
| 通道列表 | GET | `/api/v2/gb28181/devices/{id}/channels` | 获取设备通道列表 |
| 查询目录 | POST | `/api/v2/gb28181/devices/{id}/catalog` | 主动查询设备目录 |
| 删除设备 | DELETE | `/api/v2/gb28181/devices/{id}` | 删除设备及通道 |

### 3.2 流控制

| 接口 | 方法 | 路径 | 说明 |
|------|------|------|------|
| 开始直播 | POST | `/api/v2/gb28181/channels/start-live` | 启动实时视频流 |
| 停止直播 | POST | `/api/v2/gb28181/channels/stop-live` | 停止视频流 |
| 播放地址 | GET | `/api/v2/gb28181/channels/play-urls` | 获取多协议播放URL |
| 历史回放 | POST | `/api/v2/gb28181/channels/playback` | 启动录像回放 |
| PTZ控制 | POST | `/api/v2/gb28181/channels/ptz` | 云台控制 |

### 3.3 Hook回调

| 接口 | 方法 | 路径 | 说明 |
|------|------|------|------|
| 通用Hook | POST | `/api/v2/gb/server/hock` | 接收信令网关推送 |

## 四、关键配置项

### 4.1 信令网关配置（gb28181_server.php）

```php
$config = [
    // SIP服务器配置
    'sip_server' => [
        'ip' => '0.0.0.0',
        'port' => 5060,
        'domain' => '3402000000',
        'server_id' => '34020000002000000001',
    ],
    
    // Hook推送地址
    'hook_url' => 'http://127.0.0.1:8787/api/v2/gb/server/hock',
    
    // Redis配置（订阅命令）
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'channel' => 'gb28181:commands',
    ],
];
```

### 4.2 API项目配置（config/zlm.php）

```php
return [
    'host' => env('ZLM_HOST', '127.0.0.1'),
    'port' => env('ZLM_PORT', 80),
    'secret' => env('ZLM_SECRET', ''),
    'rtp_port_start' => 30000,
    'rtp_port_end' => 40000,
];
```

## 五、调试与日志

### 5.1 查看信令网关日志

```bash
tail -f /path/to/php-exosip/logs/gb28181.log
```

### 5.2 查看API日志

```bash
# SIP相关日志
tail -f /path/to/gbvr-iot/runtime/logs/sip.log

# 应用日志
tail -f /path/to/gbvr-iot/runtime/logs/webman.log
```

### 5.3 查看Redis命令

```bash
# 订阅Redis通道
redis-cli
> SUBSCRIBE gb28181:commands

# 发布测试命令
> PUBLISH gb28181:commands '{"command":"query_catalog","device_id":"34020000001320000001"}'
```

### 5.4 查看ZLM流列表

```bash
curl "http://127.0.0.1/index/api/getMediaList?secret=your_secret"
```

## 六、常见问题

### Q1: 设备注册不上？

**排查步骤：**
1. 检查信令网关是否启动：`ps aux | grep gb28181_server`
2. 检查端口是否监听：`netstat -an | grep 5060`
3. 检查设备配置的服务器IP和端口
4. 查看信令网关日志是否收到REGISTER

### Q2: 直播无法播放？

**排查步骤：**
1. 检查ZLM是否启动：`ps aux | grep MediaServer`
2. 检查RTP端口是否正常分配
3. 检查设备是否推流：`curl http://127.0.0.1/index/api/getMediaList`
4. 检查SSRC是否正确更新：查看API日志中的`media_ready`处理
5. 使用VLC测试RTSP地址

### Q3: SSRC冲突？

**解决方法：**
- API项目在`device_channels`表中使用唯一索引保证SSRC不重复
- 生成逻辑：10位随机数字 + 数据库唯一性检查
- 每个通道在首次保存目录时生成固定SSRC

### Q4: Redis命令未执行？

**排查步骤：**
1. 检查信令网关是否订阅Redis：查看启动日志
2. 检查Redis连接是否正常：`redis-cli ping`
3. 检查频道名称是否一致：`gb28181:commands`
4. 手动发布测试命令验证

## 七、性能优化建议

1. **数据库索引优化**
   - `devices.device_id`: 唯一索引
   - `device_channels.ssrc`: 唯一索引
   - `stream_sessions.session_id`: 唯一索引

2. **Redis连接池**
   - 使用持久连接
   - 配置连接池大小

3. **ZLM端口管理**
   - 设置合理的端口范围（30000-40000）
   - 及时释放未使用的端口

4. **日志级别**
   - 生产环境设置为INFO
   - 调试时使用DEBUG

## 八、下一步工作

- [ ] 补充会话管理逻辑（在`handleMediaReady`中关联session_id）
- [ ] 实现录像下载功能
- [ ] 实现语音对讲功能
- [ ] 添加设备在线监控（心跳超时检测）
- [ ] 实现多ZLM负载均衡
- [ ] 添加流媒体质量监控
- [ ] 完善错误处理和重试机制
