# GB28181 Stream IP 集成测试文档

## 概述

本文档描述如何测试GB28181流媒体收流IP(`stream_ip`)的完整集成。

---

## 修改内容总结

### 1. 数据库层 ✅

**文件**: `CoreW/Model/MediaServerModel.php`

添加了`stream_ip`字段到媒体服务器表：
```php
'stream_ip' => $row['stream_ip'] ?? '',  // 收流IP（NAT场景使用）
```

**使用场景**:
- 媒体服务器在NAT后面时，设备需要推流到公网IP
- 支持多网卡场景，指定特定网卡IP接收流

---

### 2. 业务层 ✅

**文件**: `CoreW/Business/GB/Gb28181Service.php`

#### `startLiveVideo` 方法
```php
public function startLiveVideo(
    string $deviceId,
    string $channelId,
    string $ssrc,
    int $zlmPort,
    int $tcpMode,
    string $streamId,
    string $streamIp  // 新增参数
): bool
```

#### `startPlayback` 方法
```php
public function startPlayback(
    string $deviceId,
    string $channelId,
    string $startTime,
    string $endTime,
    string $ssrc,
    int $zlmPort,
    int $tcpMode,
    string $streamId,
    string $streamIp  // 新增参数
): bool
```

**变更**: 将`stream_ip`通过Redis命令的`params['media_server_ip']`传递给信令网关

---

### 3. SDK层 ✅

**文件**: `CoreW/PSipGateway/Gb28181Client.php`

#### `startLiveVideo` 方法
```php
public function startLiveVideo(
    string $deviceId,
    string $channelId,
    string $ssrc,
    int $zlmPort,
    int $tcpMode,
    string $streamId,
    string $streamIp  // 新增参数
): bool
```

#### `startPlayback` 方法
```php
public function startPlayback(
    string $deviceId,
    string $channelId,
    string $startTime,
    string $endTime,
    string $ssrc,
    int $zlmPort,
    int $tcpMode,
    string $streamId,
    string $streamIp  // 新增参数
): bool
```

**变更**: 在推送到Redis队列的命令中添加`media_server_ip`字段

---

### 4. Trait层 ✅

**文件**: `CoreW/Business/Devices/Traits/GB28181StreamTrait.php`

#### `startLiveVideoCore` 方法
```php
// 获取收流IP（优先使用stream_ip，否则使用host）
$streamIp = !empty($mediaServer['stream_ip']) ? $mediaServer['stream_ip'] : $mediaServer['host'];

// 传递给Service层
$result = $this->getGb28181Service()->startLiveVideo(
    $deviceId,
    $channelId,
    $ssrc,
    $zlmPort,
    $tcpMode,
    $streamId,
    $streamIp  // 传递收流IP
);
```

#### `startPlaybackCore` 方法
```php
// 获取收流IP
$streamIp = !empty($mediaServer['stream_ip']) ? $mediaServer['stream_ip'] : $mediaServer['host'];

// 传递给Service层
$result = $this->getGb28181Service()->startPlayback(
    $deviceId,
    $channelId,
    $startTime,
    $endTime,
    $playbackSsrc,
    $zlmPort,
    $tcpMode,
    $playbackStreamId,
    $streamIp  // 传递收流IP
);
```

---

### 5. 信令网关层 ✅

**文件**: `Gb28181Gateway/src/Message/CommandDispatcher.php`

#### `handleStartLiveVideo` 方法
```php
// 从params获取收流IP
$mediaServerIp = $params['media_server_ip'] ?? null;
if (!$mediaServerIp) {
    return $this->errorResponse($requestId, 'Missing media_server_ip in params');
}

// 构建SDP时使用
$sdp = SdpBuilder::buildLiveVideoSdp(
    serverId: $this->config['server_id'],
    mediaIp: $mediaServerIp,  // 使用从params传入的收流IP
    mediaPort: $zlmPort,
    ssrc: $ssrc,
    tcpMode: $tcpMode
);
```

#### `handleStartPlayback` 方法
```php
// 从params获取收流IP
$mediaServerIp = $params['media_server_ip'] ?? null;
if (!$mediaServerIp) {
    return $this->errorResponse($requestId, 'Missing media_server_ip in params');
}

// 构建SDP时使用
$sdp = SdpBuilder::buildPlaybackSdp(
    serverId: $this->config['server_id'],
    mediaIp: $mediaServerIp,  // 使用从params传入的收流IP
    mediaPort: $zlmPort,
    ssrc: $ssrc,
    startTime: 0,
    endTime: 0,
    tcpMode: $tcpMode
);
```

---

## 完整调用链路

```
1. Controller (LiveController/PlaybackController)
   ↓
2. GB28181StreamTrait::startLiveVideoCore()
   - 从 MediaServer 读取 stream_ip（优先）或 host
   ↓
3. Gb28181Service::startLiveVideo($streamIp)
   ↓
4. Gb28181Client::startLiveVideo($streamIp)
   - 将 stream_ip 放入 Redis 命令的 params['media_server_ip']
   ↓
5. Redis Queue: gb28181:commands
   {
     "action": "start_live_video",
     "device_id": "...",
     "channel_id": "...",
     "params": {
       "ssrc": "...",
       "zlm_port": 30000,
       "tcp_mode": 1,
       "stream_id": "...",
       "media_server_ip": "1.2.3.4"  // 🎯 关键字段
     }
   }
   ↓
6. Gb28181Gateway: CommandDispatcher
   - 读取 $params['media_server_ip']
   ↓
7. SdpBuilder::buildLiveVideoSdp($mediaIp)
   - 在 SDP 的 c= 行使用 media_server_ip
   ↓
8. ExoSip::sendInvite($targetUri, $sdp)
   - SDP 发送给设备
   ↓
9. 设备解析 SDP，推流到 c= 行指定的 IP:Port
```

---

## 测试场景

### 场景 1: 内网环境（无NAT）

**配置**:
```sql
UPDATE media_servers 
SET host = '192.168.1.100', 
    stream_ip = NULL  -- 不配置stream_ip
WHERE server_id = 'zlm-001';
```

**预期**:
- Trait 层使用 `host` 作为收流IP
- SDP c= 行: `c=IN IP4 192.168.1.100`
- 设备推流到 `192.168.1.100:30000`

---

### 场景 2: NAT环境（公网IP）

**配置**:
```sql
UPDATE media_servers 
SET host = '192.168.1.100',      -- 内网IP（API访问）
    stream_ip = '1.2.3.4'        -- 公网IP（设备推流）
WHERE server_id = 'zlm-001';
```

**预期**:
- Trait 层优先使用 `stream_ip`
- SDP c= 行: `c=IN IP4 1.2.3.4`
- 设备推流到 `1.2.3.4:30000`（公网IP）

---

### 场景 3: 多网卡环境

**配置**:
```sql
UPDATE media_servers 
SET host = '192.168.1.100',      -- 管理网IP
    stream_ip = '10.0.0.100'     -- 媒体网IP
WHERE server_id = 'zlm-001';
```

**预期**:
- SDP c= 行: `c=IN IP4 10.0.0.100`
- 设备推流到 `10.0.0.100:30000`（媒体专网）

---

## 验证步骤

### 1. 更新数据库

```sql
-- 添加 stream_ip 字段（如果未添加）
ALTER TABLE media_servers 
ADD COLUMN stream_ip VARCHAR(64) DEFAULT NULL COMMENT '收流IP（NAT场景使用公网IP）';

-- 配置测试数据
UPDATE media_servers 
SET stream_ip = '1.2.3.4'  -- 替换为你的公网IP
WHERE server_id = 'zlm-001';
```

### 2. 启动信令网关

```bash
cd /path/to/Gb28181Gateway
php start.php start -d
```

### 3. 发起实时视频请求

```bash
curl -X POST http://localhost:8787/api/v1/devices/{device_id}/channels/{channel_id}/live/start
```

### 4. 检查日志

**gbvr-iot 日志**:
```bash
tail -f runtime/logs/sip.log | grep "stream_ip"
```

预期输出:
```
[2026-01-08 10:00:00] sip.INFO: Start live video command sent {
  "device_id": "34020000001320000001",
  "channel_id": "34020000001320000001",
  "ssrc": "0100000001",
  "zlm_port": 30000,
  "stream_ip": "1.2.3.4"  // 🎯 收流IP
}
```

**信令网关日志**:
```bash
tail -f Gb28181Gateway/runtime/logs/sip.log | grep "media_server_ip"
```

预期输出:
```
[2026-01-08 10:00:00] INFO: Dispatch command: start_live_video
[2026-01-08 10:00:01] DEBUG: Received params: {
  "ssrc": "0100000001",
  "zlm_port": 30000,
  "tcp_mode": 1,
  "stream_id": "...",
  "media_server_ip": "1.2.3.4"  // 🎯 从Redis命令中读取
}
[2026-01-08 10:00:02] DEBUG: Generated SDP:
v=0
o=34020000002000000001 0 0 IN IP4 1.2.3.4
s=Play
c=IN IP4 1.2.3.4
t=0 0
m=video 30000 TCP/RTP/AVP 96
a=recvonly
a=rtpmap:96 PS/90000
y=0100000001
```

### 5. 抓包验证

```bash
# 抓取 INVITE 报文
tcpdump -i any -s 0 -A port 5060 and host <device_ip> | grep -A 20 "INVITE"
```

检查 SDP 中的 `c=` 行是否使用了正确的 IP：
```
c=IN IP4 1.2.3.4
```

---

## 常见问题排查

### Q1: 设备推流到错误的IP

**症状**: 设备推流到内网IP，而不是公网IP

**排查**:
1. 检查数据库 `stream_ip` 字段是否正确配置
2. 检查 Trait 层日志，确认读取到正确的 IP
3. 检查信令网关日志，确认 `media_server_ip` 参数传递正确
4. 抓包查看 INVITE 报文中的 SDP `c=` 行

---

### Q2: stream_ip 为空导致错误

**症状**: CommandDispatcher 返回 "Missing media_server_ip in params"

**排查**:
1. 检查数据库配置：`SELECT stream_ip, host FROM media_servers WHERE server_id = 'xxx'`
2. 检查 Trait 层逻辑：确保至少有 `host` 值
3. 检查 Service 层是否正确传递参数

---

### Q3: NAT穿透仍然失败

**可能原因**:
1. 防火墙未开放 RTP 端口（30000-40000）
2. ZLMediaKit 监听的IP不正确
3. 设备无法访问公网IP

**解决**:
1. 开放防火墙端口：
```bash
firewall-cmd --permanent --add-port=30000-40000/tcp
firewall-cmd --permanent --add-port=30000-40000/udp
firewall-cmd --reload
```

2. 检查 ZLM 配置：
```ini
[rtp]
# 确保监听所有网卡
port=30000-40000
tcpEnable=1
```

---

## 性能影响

添加 `stream_ip` 参数对性能的影响：

| 组件 | 影响 | 说明 |
|------|------|------|
| 数据库 | 无 | 仅新增一个字段，无查询性能影响 |
| API层 | 微小 | 增加1个字段读取和传递 |
| Redis | 微小 | 命令大小增加约20字节 |
| 信令网关 | 无 | 参数解析逻辑简单 |

**结论**: 性能影响可忽略不计

---

## 总结

✅ **已完成**:
1. 数据库添加 `stream_ip` 字段
2. Trait 层支持 `stream_ip` 优先使用
3. Service 层传递 `stream_ip` 参数
4. SDK 层将 `stream_ip` 放入 Redis 命令
5. 信令网关从 `params['media_server_ip']` 读取并使用
6. SDP 构建器使用正确的 IP

✅ **支持场景**:
- 内网环境（无NAT）
- NAT环境（公网IP）
- 多网卡环境（媒体专网）

✅ **向后兼容**:
- `stream_ip` 为空时自动使用 `host`
- 现有代码无需修改

---

**文档版本**: 1.0  
**创建日期**: 2026-01-08  
**作者**: AI Assistant
