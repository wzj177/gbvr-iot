# GB28181 架构重构总结

## 重构目标

将 GB28181 实现从单体架构重构为微服务架构，实现**职责分离**和**松耦合**。

## 架构对比

### 重构前（单体架构）

```
┌─────────────────────────────────────────┐
│     GB28181Handler (信令网关)            │
│  ┌────────────────────────────────────┐ │
│  │ SIP 信令处理                        │ │
│  ├────────────────────────────────────┤ │
│  │ ZLM 客户端集成                      │ │
│  │ - openRtpServer()                  │ │
│  │ - closeRtpServer()                 │ │
│  │ - getPlayUrls()                    │ │
│  ├────────────────────────────────────┤ │
│  │ SSRC 生成                           │ │
│  │ - generateSsrc()                   │ │
│  ├────────────────────────────────────┤ │
│  │ 会话管理                            │ │
│  │ - startLiveVideo()                 │ │
│  │ - stopLiveVideo()                  │ │
│  │ - buildInviteSdp()                 │ │
│  └────────────────────────────────────┘ │
└─────────────────────────────────────────┘
```

**问题：**
- ❌ 职责不清：SIP 信令 + ZLM 集成 + 业务逻辑混杂
- ❌ SSRC 硬编码："0100000001"，多路流冲突
- ❌ 难以扩展：添加新功能需要修改核心代码
- ❌ 无法独立部署：网关和业务逻辑耦合

### 重构后（微服务架构）

```
┌─────────────────────────────────────────────────────────┐
│         信令网关 (php-exosip/examples)                   │
│  ┌────────────────────────────────────────────────────┐ │
│  │ GB28181Handler - 纯 SIP 信令处理                    │ │
│  │ - REGISTER/INVITE/BYE 处理                         │ │
│  │ - SDP 解析（接收设备 SSRC）                         │ │
│  │ - Hook 推送（注册、心跳、目录、media_ready）        │ │
│  ├────────────────────────────────────────────────────┤ │
│  │ CommandDispatcher - Redis 命令处理                  │ │
│  │ - 接收 API 项目命令                                 │ │
│  │ - 使用外部提供的 SSRC/zlm_port                      │ │
│  │ - 构建 SDP 并发送 INVITE                            │ │
│  └────────────────────────────────────────────────────┘ │
└────────────┬───────────────────────────────────────┬────┘
             │ HTTP Hook                  Redis Pub/Sub
             ↓                                       ↑
┌────────────────────────────────────────────────────────┐
│           API 项目 (gbvr-iot)                          │
│  ┌──────────────────────────────────────────────────┐ │
│  │ GBServerHockController - Hook 接收               │ │
│  │ - register: 存储设备信息                         │ │
│  │ - save_catalog: 保存通道（生成 SSRC）            │ │
│  │ - media_ready: 更新 ZLM 设备 SSRC                │ │
│  ├──────────────────────────────────────────────────┤ │
│  │ GB28181DeviceController - 设备管理               │ │
│  │ - 设备列表、详情、通道查询                        │ │
│  │ - 目录查询（发布 Redis 命令）                     │ │
│  ├──────────────────────────────────────────────────┤ │
│  │ GB28181StreamController - 流控制                 │ │
│  │ - startLive: 生成 SSRC + 分配 ZLM 端口           │ │
│  │ - 发布 Redis 命令（携带 SSRC/port/tcp_mode）     │ │
│  │ - stopLive: 停止流并释放资源                     │ │
│  ├──────────────────────────────────────────────────┤ │
│  │ ZLMClient SDK - ZLM 集成                         │ │
│  │ - openRtpServer(tcp_mode)                        │ │
│  │ - updateRtpServerSsrc(device_ssrc)               │ │
│  │ - getPlayUrls()                                  │ │
│  ├──────────────────────────────────────────────────┤ │
│  │ Database - 数据持久化                            │ │
│  │ - devices: 设备表                                │ │
│  │ - device_channels: 通道表（SSRC 唯一索引）       │ │
│  │ - stream_sessions: 会话表                        │ │
│  └──────────────────────────────────────────────────┘ │
└────────────────────────────────────────────────────────┘
```

**优势：**
- ✅ 职责清晰：网关只处理 SIP，API 处理业务
- ✅ SSRC 管理：数据库保证唯一性，支持多路流
- ✅ 松耦合：通过 Hook + Redis 通信
- ✅ 易扩展：添加功能只需修改 API 项目
- ✅ 独立部署：网关和 API 可独立升级

## 核心改动

### 1. GB28181Handler.php（信令网关）

#### 移除的代码

```php
// ❌ 移除：ZLM 客户端属性
private $zlmClient;

// ❌ 移除：SSRC 生成（视频流）
private function generateSsrc(): string
{
    return str_pad((string)rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
}

// ❌ 移除：完整的 startLiveVideo 方法（含 ZLM 集成）
public function startLiveVideo(string $deviceId, string $channelId, array $options = []): ?array
{
    // 1. 调用 ZLM 分配端口
    // 2. 生成 SSRC
    // 3. 构建 SDP
    // 4. 发送 INVITE
    // 5. 保存会话
}

// ❌ 移除：stopLiveVideo、cleanupSession、buildInviteSdp
// ❌ 移除：getSession、getAllSessions、getPlayUrls
```

#### 保留的代码

```php
// ✅ 保留：generateSsrc() 但仅用于语音对讲
/**
 * 生成SSRC（仅用于语音对讲）
 * 
 * 注意：视频流的SSRC由API项目管理，存储在device_channels表中
 * 这个方法只用于语音对讲的临时SSRC生成
 */
private function generateSsrc(): string
{
    return str_pad((string)rand(1000000000, 9999999999), 10, '0', STR_PAD_LEFT);
}

// ✅ 保留：handleInviteResponse 简化版
private function handleInviteResponse(\SipEvent $event): void
{
    // 解析设备 SDP
    // 提取设备 SSRC
    
    // 通过 Hook 推送给 API 项目
    $this->postTask('media_ready', [
        'call_id' => $callId,
        'device_ssrc' => $ssrc,  // 关键！
        'sdp' => $sdp,
    ]);
}

// ✅ 保留：handleBye 简化版
public function handleBye(\SipEvent $event): void
{
    // 通知 API 项目会话结束
    $this->postTask('session_bye', [
        'device_id' => $deviceId,
        'call_id' => $callId,
    ]);
}
```

### 2. CommandDispatcher.php（信令网关）

#### 修改前

```php
public function handleStartLiveVideo($params)
{
    // ❌ 自己生成 SSRC
    $ssrc = "0100000001";  // 硬编码！
    
    // ❌ 自己分配端口
    $port = $this->allocatePort();
    
    $sdp = $this->buildInviteSdp($port, $ssrc);
    // ...
}

private function buildInviteSdp($port, $ssrc)
{
    // 硬编码 SSRC
    return "y=0100000001\r\n";
}
```

#### 修改后

```php
public function handleStartLiveVideo($params)
{
    // ✅ 从 params 接收 SSRC 和端口
    $ssrc = $params['ssrc'] ?? '';
    $zlmPort = $params['zlm_port'] ?? 0;
    $tcpMode = $params['tcp_mode'] ?? 0;
    
    if (!$ssrc || !$zlmPort) {
        return ['success' => false, 'error' => 'Missing ssrc or zlm_port'];
    }
    
    $sdp = $this->buildInviteSdp($zlmPort, $ssrc, $tcpMode);
    // ...
}

private function buildInviteSdp($port, $ssrc, $tcpMode)
{
    // ✅ 使用传入的 SSRC
    return "y={$ssrc}\r\n";
}
```

### 3. API 项目（gbvr-iot）

#### 新增文件

```
gbvr-iot/
├── app/api/v2/controller/
│   ├── GBServerHockController.php      # Hook 接收器
│   ├── GB28181DeviceController.php     # 设备管理 API
│   └── GB28181StreamController.php     # 流控制 API
├── CoreW/Sdk/ZLMediaKit/
│   └── ZLMClient.php                   # ZLM SDK
├── config/
│   ├── zlm.php                         # ZLM 配置（含 tcp_mode）
│   └── routes/v1.php                   # 路由配置
├── database/migrations/
│   └── gb28181_tables.sql              # 数据库表结构
└── docs/
    ├── GB28181_INTEGRATION_GUIDE.md    # 集成指南
    └── ARCHITECTURE_REFACTORING.md     # 本文档
```

#### GBServerHockController.php

```php
/**
 * 处理媒体流就绪（收到设备200 OK，包含设备SSRC）
 */
private function handleMediaReady(array $body): void
{
    $callId = $body['call_id'];
    $deviceSsrc = $body['device_ssrc'];  // 从 Hook 接收
    
    // 从会话表查询 stream_id
    $session = Db::table('stream_sessions')
        ->where('call_id', $callId)
        ->first();
    
    if ($session && $deviceSsrc) {
        // ✅ 更新 ZLM：告知设备实际使用的 SSRC
        $this->zlmClient->updateRtpServerSsrc([
            'stream_id' => $session->stream_id,
            'ssrc' => $deviceSsrc,
        ]);
    }
}
```

#### GB28181StreamController.php

```php
public function startLive(Request $request)
{
    // 1. 查询通道，获取预分配的 SSRC
    $channel = Db::table('device_channels')
        ->where('device_id', $deviceId)
        ->where('channel_id', $channelId)
        ->first();
    
    $ssrc = $channel->ssrc;  // ✅ 从数据库获取
    
    // 2. 调用 ZLM 分配端口
    $tcpMode = config('zlm.default_tcp_mode', 1);  // ✅ 支持 tcp_mode
    $zlmResult = $this->zlmClient->openRtpServer(
        $channel->stream_id,
        0,
        $tcpMode,
        true,
        $ssrc
    );
    
    $zlmPort = $zlmResult['port'];
    
    // 3. 发布 Redis 命令到信令网关
    Redis::publish('gb28181:commands', json_encode([
        'action' => 'start_live_video',
        'device_id' => $deviceId,
        'channel_id' => $channelId,
        'params' => [
            'ssrc' => $ssrc,           // ✅ 传递给网关
            'zlm_port' => $zlmPort,    // ✅ 传递给网关
            'tcp_mode' => $tcpMode,    // ✅ 传递给网关
        ],
    ]));
}
```

## SSRC 管理流程

### 重构前

```
1. 网关硬编码 SSRC = "0100000001"
2. 所有流使用相同 SSRC
3. 多路流冲突 ❌
```

### 重构后

```
1. 设备目录查询
   ↓
2. API 接收 save_catalog Hook
   ↓
3. 为每个通道生成唯一 SSRC（数据库唯一索引保证）
   INSERT INTO device_channels (ssrc, ...) VALUES ('1234567890', ...)
   ↓
4. 客户端请求启动直播
   ↓
5. API 查询通道 SSRC
   SELECT ssrc FROM device_channels WHERE ...
   ↓
6. API 分配 ZLM 端口（使用通道 SSRC）
   ZLM.openRtpServer(stream_id, port=0, tcp_mode, ssrc='1234567890')
   ↓
7. API 发布 Redis 命令（携带 SSRC）
   Redis.publish('gb28181:commands', {ssrc: '1234567890', zlm_port: 35024})
   ↓
8. 网关接收命令，构建 SDP（使用 API 提供的 SSRC）
   SDP: y=1234567890
   ↓
9. 设备返回 200 OK（含设备实际 SSRC）
   设备 SSRC: 0987654321
   ↓
10. 网关推送 media_ready Hook（携带设备 SSRC）
    Hook: {device_ssrc: '0987654321'}
    ↓
11. API 更新 ZLM（使用设备 SSRC）
    ZLM.updateRtpServerSsrc(stream_id, ssrc='0987654321')
    ↓
12. 设备推流 ✅
```

## RTP 传输模式支持

### 新增 tcp_mode 参数

| 模式 | tcp_mode | 适用场景 | NAT 穿透 |
|------|----------|----------|----------|
| UDP | 0 | 局域网 | ❌ 困难 |
| TCP 被动 | 1 | **公网推荐** | ✅ 简单 |
| TCP 主动 | 2 | 特殊场景 | ❌ 困难 |

### 配置方式

```php
// config/zlm.php
return [
    'default_tcp_mode' => 1,  // 公网推荐 TCP 被动模式
];
```

### 使用流程

```
API 读取配置 tcp_mode=1
  ↓
调用 ZLM.openRtpServer(tcp_mode=1)
  ↓
ZLM 监听 TCP 端口 35024
  ↓
Redis 命令携带 tcp_mode=1
  ↓
网关构建 SDP（TCP/RTP）
  ↓
设备主动连接 35024 端口 ✅
```

## 数据库设计

### devices 表

```sql
CREATE TABLE devices (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  device_id VARCHAR(32) UNIQUE NOT NULL,     -- 设备国标ID
  status ENUM('online','offline'),
  last_heartbeat_at DATETIME,
  ...
);
```

### device_channels 表

```sql
CREATE TABLE device_channels (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  device_id VARCHAR(32) NOT NULL,
  channel_id VARCHAR(32) NOT NULL,
  ssrc VARCHAR(10) UNIQUE NOT NULL,           -- ✅ SSRC 唯一索引
  stream_id VARCHAR(64) UNIQUE NOT NULL,      -- {device_id}_{channel_id}
  status ENUM('online','offline','streaming'),
  ...
  UNIQUE KEY uk_device_channel (device_id, channel_id),
  UNIQUE KEY uk_ssrc (ssrc),                   -- ✅ 保证 SSRC 唯一
);
```

### stream_sessions 表

```sql
CREATE TABLE stream_sessions (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  session_id VARCHAR(64) UNIQUE NOT NULL,
  call_id VARCHAR(255),
  device_id VARCHAR(32),
  channel_id VARCHAR(32),
  ssrc VARCHAR(10),
  stream_id VARCHAR(64),
  type ENUM('live','playback'),
  status ENUM('inviting','active','stopped'),
  zlm_port INT,
  ...
);
```

## 通信协议

### Hook 推送（网关 → API）

```http
POST /api/v2/gb/server/hock
Content-Type: application/x-www-form-urlencoded

scene=media_ready
&body[call_id]=abc123
&body[device_ssrc]=0987654321
&body[device_ip]=192.168.1.200
&body[device_port]=8000
```

### Redis 命令（API → 网关）

```json
{
  "action": "start_live_video",
  "device_id": "34020000001320000001",
  "channel_id": "34020000001320000002",
  "params": {
    "ssrc": "1234567890",
    "zlm_port": 35024,
    "tcp_mode": 1,
    "stream_id": "34020000001320000001_34020000001320000002"
  }
}
```

## 测试验证

### 1. 启动服务

```bash
# 启动 ZLM
./MediaServer -d

# 启动信令网关
php gb28181_server.php

# 启动 API 服务
php start.php start
```

### 2. 设备注册

```
设备 REGISTER → 网关
  ↓
网关推送 Hook → API
  ↓
API 存储设备信息到 devices 表 ✅
```

### 3. 查询目录

```bash
curl -X POST http://localhost:8787/api/v2/gb28181/devices/34020000001320000001/catalog
```

```
API 发布 Redis 命令
  ↓
网关发送 MESSAGE (Catalog 查询)
  ↓
设备返回目录 XML
  ↓
网关推送 save_catalog Hook
  ↓
API 保存通道（生成唯一 SSRC）✅
```

### 4. 启动直播

```bash
curl -X POST http://localhost:8787/api/v2/gb28181/channels/start-live \
  -H "Content-Type: application/json" \
  -d '{
    "device_id": "34020000001320000001",
    "channel_id": "34020000001320000002"
  }'
```

```
API 查询 SSRC → 1234567890
  ↓
API 调用 ZLM.openRtpServer(ssrc=1234567890, tcp_mode=1) → port 35024
  ↓
API 发布 Redis 命令 {ssrc, zlm_port, tcp_mode}
  ↓
网关构建 SDP: y=1234567890
  ↓
网关发送 INVITE
  ↓
设备返回 200 OK: y=0987654321
  ↓
网关推送 media_ready Hook {device_ssrc: 0987654321}
  ↓
API 更新 ZLM: updateRtpServerSsrc(0987654321)
  ↓
设备推流 RTP → ZLM ✅
```

### 5. 获取播放地址

```bash
curl http://localhost:8787/api/v2/gb28181/channels/play-urls?stream_id=xxx
```

```json
{
  "rtsp": "rtsp://192.168.1.100/rtp/...",
  "flv": "http://192.168.1.100/rtp/....live.flv",
  "hls": "http://192.168.1.100/rtp/.../hls.m3u8"
}
```

## 重构收益

### 1. 职责分离

- **信令网关**：专注 SIP 协议处理
- **API 项目**：处理业务逻辑、数据持久化、ZLM 集成

### 2. SSRC 管理优化

- 从硬编码改为数据库管理
- 唯一索引保证不冲突
- 支持多路并发流

### 3. 扩展性提升

- 添加新功能只需修改 API 项目
- 网关代码稳定，减少变更风险
- 支持多种传输模式（UDP/TCP）

### 4. 公网部署友好

- TCP 被动模式支持
- 只需开放服务器端口
- 无需设备端口映射

### 5. 可维护性提高

- 代码职责清晰
- 调试更容易
- 文档完善

## 后续工作

- [ ] 完善会话管理（在 media_ready 中关联 session_id）
- [ ] 实现录像下载功能
- [ ] 实现语音对讲（WebRTC 中继）
- [ ] 添加设备在线监控（心跳超时检测）
- [ ] 实现多 ZLM 负载均衡
- [ ] 添加流媒体质量监控
- [ ] 完善错误处理和重试机制

## 总结

通过本次重构，GB28181 实现从**单体架构**升级为**微服务架构**，实现了：

✅ **职责分离**：信令网关 ↔ API 业务  
✅ **SSRC 管理**：数据库唯一性保证  
✅ **松耦合通信**：Hook + Redis  
✅ **公网部署**：TCP 被动模式支持  
✅ **易于扩展**：API 项目独立开发  

架构更加清晰、稳定、可维护！
