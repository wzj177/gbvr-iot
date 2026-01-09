# PHP 网关层待开发任务清单

> 本文档列出 gbvr-iot (Gb28181Gateway) PHP 层需要开发的功能

**项目路径**: `/Users/jiechengyang/src/c-app/php-exosip/examples/gbvr-iot`

---

## 🔴 高优先级

### 1. 设备配置扩展功能完整实现

**状态**: 🟡 已完成数据库和 Device 类，需完善业务逻辑

**已完成**:
- ✅ 数据库迁移 (migrations/20260106000001_device_config_extend.php)
- ✅ Device 类扩展 (Gb28181Gateway/src/Device/Device.php)
- ✅ DeviceManager 配置更新方法

**待实现**:

#### ~~1.1 字符集编码处理 - 进度更新-已完成~~
**位置**: `GB28181Handler.php`

```php
// 需要实现
private function normalizeXmlEncoding(string $body, Device $device): string
{
    $charset = $device->charset; // 'gb2312' or 'utf8'
    
    // 检测实际编码并转换
    if ($charset === 'gb2312') {
        $encoding = mb_detect_encoding($body, ['UTF-8', 'GB2312', 'GBK'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $body = mb_convert_encoding($body, 'UTF-8', $encoding);
        }
    }
    
    return $body;
}
```

**调用位置**: `handleMessage()` 解析 XML 前

---

#### ~~1.2 通道类型过滤- 进度更新-已完成~~
**位置**: `Message/Command/CatalogCommand.php`

```php
// 需要实现
public function parse(\SimpleXMLElement $xml, string $deviceId, array $context = []): array
{
    $device = $context['device_manager']?->getDeviceObject($deviceId);
    $filterTypes = $device?->filterChannelTypes ?? [];
    
    $channels = [];
    foreach ($xml->DeviceList->Item as $item) {
        $channelId = (string)$item->DeviceID;
        
        // 提取通道类型码（第11-13位）
        $typeCode = (int)substr($channelId, 10, 3);
        
        // 过滤
        if (in_array($typeCode, $filterTypes)) {
            continue;
        }
        
        $channels[] = [/* ... */];
    }
    
    return ['channels' => $channels];
}
```

**通道类型码参考**:
- 131: 球机
- 132: 半球
- 133: 固定枪机
- 134: 报警输入
- 135: 报警输出
- 136: 语音输入
- 137: 语音输出

---

#### ~~1.3 收流 IP 架构说明 - 进度更新-已完成~~

**架构**: 收流 IP 通过流媒体服务器表管理，而非设备级别配置

**表结构关系**:
```
gv_device_channels
    └─ media_server_id → gv_media_servers
                             ├─ host (API 地址)
                             └─ stream_ip (收流地址，需新增)
```

**实现位置**: `CommandDispatcher.php` - `handleStartLiveVideo()`

```php
private function handleStartLiveVideo(string $requestId, string $channelId, array $params): array
{
    // 1. 查询通道（包含 media_server_id）
    $channel = $this->channelDao->getChannelById($channelId);
    
    // 2. 查询流媒体服务器配置
    $mediaServer = $this->mediaServerDao->getById($channel['media_server_id']);
    
    // 3. 使用流媒体服务器的收流 IP
    $streamIp = $mediaServer['stream_ip'] ?: $mediaServer['host'];
    
    // 4. 构建 SDP
    $sdp = SdpBuilder::buildLiveVideoSdp(
        serverId: $this->config['server_id'],
        mediaIp: $streamIp,  // 从流媒体服务器获取
        mediaPort: $zlmPort,
        ssrc: $ssrc,
        tcpMode: $channel['rtp_trans_mode'] ?? 0
    );
    
    // ...
}
```

**数据库迁移**: 需要在 `gv_media_servers` 表增加 `stream_ip` 字段：
```sql
ALTER TABLE `gv_media_servers` 
ADD COLUMN `stream_ip` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '收流IP（用于SDP，为空则使用host）' AFTER `host`;
```

---

#### ~~1.4 API 配置同步实现- 进度更新-已完成~~
**位置**: `CommandDispatcher.php`

```php
// 需要添加命令处理
case 'update_device_config':
    return $this->handleUpdateDeviceConfig($requestId, $deviceId, $params);

private function handleUpdateDeviceConfig(
    string $requestId,
    string $deviceId,
    array $params
): array {
    $config = $params['config'] ?? [];
    
    // 更新内存中的设备配置
    $result = $this->deviceManager->updateDeviceConfig($deviceId, $config);
    
    if (!$result) {
        return $this->errorResponse($requestId, "Device not found: {$deviceId}");
    }

    // 如果订阅配置变化，触发订阅更新
    $device = $this->deviceManager->getDeviceObject($deviceId);
    if ($device) {
        $this->updateSubscriptions($device);
    }

    return [
        'success' => true,
        'request_id' => $requestId,
        'message' => 'Device config updated'
    ];
}
```

**API 层**: `CoreW/Sdk/PSipGateway/Gb28181Client.php`

```php
// 需要添加方法
public function updateDeviceConfig(string $deviceId, array $config): bool
{
    return $this->sendCommand($deviceId, 'update_device_config', [
        'config' => $config
    ]);
}
```

**参考文档**: [DEVICE_CONFIG_EXTEND.md](../03-功能实现/DEVICE_CONFIG_EXTEND.md)

---

### 2. SUBSCRIBE/NOTIFY 完整实现

**状态**: 🟡 已有基础，需完善

**已完成**:
- ✅ Device 类订阅配置字段
- ✅ `getSubscribeEvents()` 方法

**待实现**:

#### 2.1 Catalog 订阅（目录变更）
**位置**: `GB28181Handler.php`

```php
// 发送订阅
private function subscribeCatalog(Device $device): bool
{
    $deviceIp = $device->received_ip ?? $device->ip;
    $devicePort = $device->received_port ?? $device->port;
    
    $result = $this->sipClient->subscribe(
        to: "sip:{$device->deviceId}@{$deviceIp}:{$devicePort}",
        event: 'Catalog',
        expires: $device->subscribeExpires
    );
    
    if ($result) {
        // 记录订阅状态
        $device->subscriptions['Catalog'] = [
            'subscribed_at' => time(),
            'expires_at' => time() + $device->subscribeExpires,
        ];
    }
    
    return $result;
}

// 处理 NOTIFY
private function handleCatalogNotify(string $deviceId, string $body): void
{
    $xml = simplexml_load_string($body);
    
    // 解析 Catalog NOTIFY（与 Catalog 查询响应格式相同）
    $command = new CatalogCommand();
    $result = $command->parse($xml, $deviceId, [
        'device_manager' => $this->deviceManager
    ]);
    
    // 更新通道列表
    $device = $this->deviceManager->getDeviceObject($deviceId);
    if ($device) {
        $device->setChannels($result['channels']);
        $this->deviceManager->saveDevice($device);
    }
    
    // 通过 Redis 通知 API
    $this->notifyApi('device_catalog_update', [
        'device_id' => $deviceId,
        'channels' => $result['channels']
    ]);
}
```

---

#### 2.2 Alarm 订阅（报警事件）
**位置**: `GB28181Handler.php`

```php
private function subscribeAlarm(Device $device): bool
{
    // 类似 subscribeCatalog
    $result = $this->sipClient->subscribe(
        to: "sip:{$device->deviceId}@...",
        event: 'Alarm',
        expires: $device->subscribeExpires
    );
    
    return $result;
}

private function handleAlarmNotify(string $deviceId, string $body): void
{
    $xml = simplexml_load_string($body);
    
    // 解析报警信息
    $alarmData = [
        'device_id' => $deviceId,
        'alarm_priority' => (string)$xml->AlarmPriority,
        'alarm_method' => (string)$xml->AlarmMethod,
        'alarm_time' => (string)$xml->AlarmTime,
        'alarm_description' => (string)$xml->AlarmDescription,
        'longitude' => (float)$xml->Longitude ?? null,
        'latitude' => (float)$xml->Latitude ?? null,
    ];
    
    // 通知 API 层
    $this->notifyApi('device_alarm', $alarmData);
}
```

---

#### 2.3 MobilePosition 订阅（位置上报）
**位置**: `GB28181Handler.php`

```php
private function subscribeMobilePosition(Device $device): bool
{
    $result = $this->sipClient->subscribe(
        to: "sip:{$device->deviceId}@...",
        event: 'presence',  // MobilePosition 使用 Event: presence
        expires: $device->subscribeExpires
    );
    
    // SIP 请求需包含自定义头
    // Interval: 60  (上报间隔，秒)
    
    return $result;
}

private function handleMobilePositionNotify(string $deviceId, string $body): void
{
    $xml = simplexml_load_string($body);
    
    // 解析位置信息
    $positionData = [
        'device_id' => $deviceId,
        'time' => (string)$xml->Time,
        'longitude' => (float)$xml->Longitude,
        'latitude' => (float)$xml->Latitude,
        'speed' => (float)$xml->Speed ?? 0,
        'direction' => (float)$xml->Direction ?? 0,
        'altitude' => (float)$xml->Altitude ?? 0,
    ];
    
    // 存储到 Redis 或数据库
    $this->storePosition($deviceId, $positionData);
    
    // 通知 API 层
    $this->notifyApi('device_position', $positionData);
}
```

---

#### 2.4 订阅自动刷新
**位置**: 新建 `SubscriptionManager.php`

```php
<?php

namespace Gb28181\GateWay\Subscription;

class SubscriptionManager
{
    private DeviceManager $deviceManager;
    private SipClient $sipClient;
    
    /**
     * 检查并刷新即将过期的订阅
     */
    public function refreshExpiredSubscriptions(): void
    {
        $devices = $this->deviceManager->getAllDevices();
        
        foreach ($devices as $device) {
            if (!$device->isOnline()) {
                continue;
            }
            
            foreach ($device->subscriptions as $eventType => $subscription) {
                $expiresAt = $subscription['expires_at'] ?? 0;
                
                // 提前 5 分钟刷新
                if ($expiresAt - time() < 300) {
                    $this->refreshSubscription($device, $eventType);
                }
            }
        }
    }
    
    private function refreshSubscription(Device $device, string $eventType): bool
    {
        // 重新发送 SUBSCRIBE 请求
        switch ($eventType) {
            case 'Catalog':
                return $this->subscribeCatalog($device);
            case 'Alarm':
                return $this->subscribeAlarm($device);
            case 'presence':
                return $this->subscribeMobilePosition($device);
            default:
                return false;
        }
    }
}
```


**参考文档**:
- [国标设备扩展功能-订阅.md](国标设备扩展功能-订阅.md)
- [SUBSCRIBE_FEATURE_STATUS.md](../03-功能实现/SUBSCRIBE_FEATURE_STATUS.md)

---

### 3. 目录定时查询

**状态**: ⚠️ 未实现

**需求**: 根据 `catalog_interval` 配置定时查询设备目录

**位置**:  `/Users/jiechengyang/src/www/gbvr-iot/app/process/Task.php`


---

## 🟡 中优先级

### 4. GB28181-2022 新增命令支持

**状态**: ⚠️ 未开始

**需要实现的命令**:

#### 4.1 预置位扩展（新增字段）
**位置**: `Message/Command/PTZCommand.php`

2022 版预置位命令新增 `<Info>` 字段：

```xml
<Control>
    <CmdType>DeviceControl</CmdType>
    <SN>1</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <PTZCmd>A50F01{PresetID}000000{CheckCode}</PTZCmd>
    <Info>
        <ControlPriority>5</ControlPriority>  <!-- 控制优先级 -->
    </Info>
</Control>
```

需要更新 `PTZCommand::buildPresetCommand()` 方法。

---

#### 4.2 HomePosition（回到原点）
**位置**: `Message/Command/` - 新建 `HomePositionCommand.php`

```php
<?php

namespace Gb28181\GateWay\Message\Command;

class HomePositionCommand
{
    public static function build(string $deviceId, int $sn): string
    {
        return <<<XML
<?xml version="1.0" encoding="GB2312"?>
<Control>
    <CmdType>DeviceControl</CmdType>
    <SN>{$sn}</SN>
    <DeviceID>{$deviceId}</DeviceID>
    <HomePosition>1</HomePosition>
</Control>
XML;
    }
}
```

**CommandDispatcher 添加**:

```php
case 'home_position':
    return $this->handleHomePosition($requestId, $deviceId, $params);

private function handleHomePosition(string $requestId, string $deviceId, array $params): array
{
    $sn = $this->generateSN();
    $body = HomePositionCommand::build($deviceId, $sn);
    
    $this->querySender->sendMessage($deviceId, $body);
    
    return $this->successResponse($requestId, 'Home position command sent');
}
```

---

#### 4.3 Telezoom（远程变倍）
**位置**: 扩展 `PTZCommand.php`

```php
public static function buildTelezoomCommand(
    string $deviceId,
    int $sn,
    int $multiple  // 倍数 1-999
): string {
    $multipleHex = str_pad(dechex($multiple), 3, '0', STR_PAD_LEFT);
    
    return <<<XML
<?xml version="1.0" encoding="GB2312"?>
<Control>
    <CmdType>DeviceControl</CmdType>
    <SN>{$sn}</SN>
    <DeviceID>{$deviceId}</DeviceID>
    <TeleBoot>
        <Parameter>{$multipleHex}</Parameter>
    </TeleBoot>
</Control>
XML;
}
```

---

#### 4.4 录像下载（2022 格式）
2022 版增加了 `<RecordLocation>` 字段，表示录像位置：
- `Center`: 中心存储
- `Device`: 设备存储

需要更新 `PlaybackCommand.php`。

**参考文档**:
- [扩展2022版本国标协议方案.md](扩展2022版本国标协议方案.md)
- [GB28181_PRESET_AND_2022_GUIDE.md](GB28181_PRESET_AND_2022_GUIDE.md)
- [PRESET_2022_SUMMARY.md](PRESET_2022_SUMMARY.md)

---

### 5. 语音对讲业务层实现

**状态**: ⚠️ 未开始

**需求**: 实现完整的语音对讲流程

#### 5.1 发起语音对讲
**位置**: `CommandDispatcher.php`

```php
case 'start_audio_talk':
    return $this->handleStartAudioTalk($requestId, $deviceId, $params);

private function handleStartAudioTalk(string $requestId, string $deviceId, array $params): array
{
    $device = $this->deviceManager->getDeviceObject($deviceId);
    if (!$device) {
        return $this->errorResponse($requestId, 'Device not found');
    }
    
    // 1. 从 ZLM 分配端口
    $zlmPort = $this->allocateZlmPort($deviceId, 'audio_talk');
    
    // 2. 构建 Audio SDP
    $ssrc = $this->generateSSRC();
    $sdp = SdpBuilder::buildAudioTalkSdp(
        serverId: $this->config['server_id'],
        mediaIp: $this->config['media_server_ip'],
        mediaPort: $zlmPort,
        ssrc: $ssrc,
        tcpMode: $device->rtpTransMode
    );
    
    // 3. 发送 INVITE
    $result = $this->sipClient->invite(
        to: $device->uri,
        sdp: $sdp
    );
    
    return [
        'success' => true,
        'request_id' => $requestId,
        'ssrc' => $ssrc,
        'port' => $zlmPort,
    ];
}
```

#### 5.2 SDP 构建
**位置**: `Media/SdpBuilder.php`

```php
public static function buildAudioTalkSdp(
    string $serverId,
    string $mediaIp,
    int $mediaPort,
    string $ssrc,
    int $tcpMode = 0
): string {
    $sdp = "v=0\r\n";
    $sdp .= "o={$serverId} 0 0 IN IP4 {$mediaIp}\r\n";
    $sdp .= "s=Talk\r\n";
    $sdp .= "c=IN IP4 {$mediaIp}\r\n";
    $sdp .= "t=0 0\r\n";
    
    if ($tcpMode === 0) {
        $sdp .= "m=audio {$mediaPort} RTP/AVP 8 0\r\n";
    } else {
        $sdp .= "m=audio {$mediaPort} TCP/RTP/AVP 8 0\r\n";
        $sdp .= "a=setup:" . ($tcpMode === 1 ? "passive" : "active") . "\r\n";
    }
    
    $sdp .= "a=rtpmap:8 PCMA/8000\r\n";
    $sdp .= "a=rtpmap:0 PCMU/8000\r\n";
    $sdp .= "a=sendrecv\r\n";
    $sdp .= "y={$ssrc}\r\n";
    
    return $sdp;
}
```

**参考文档**: [语音对讲.md](../03-功能实现/语音对讲.md)

---

### 6. 录像回放增强

**状态**: 🟡 基础实现，需完善

**待完善**:
- ⚠️ 回放进度控制（快进、快退、暂停）
- ⚠️ 支持时间段录像下载
- ⚠️ 录像片段合并

**位置**: `CommandDispatcher.php` - `handlePlayback()`

需要实现：
- `PLAY rtsp://... RTSP/1.0` - 播放控制
- `PAUSE` - 暂停
- `TEARDOWN` - 停止

---

## 🟢 低优先级

### 7. 设备状态监控优化

**需求**:
- 设备在线状态更精确的判断
- 超时设备自动清理
- 设备性能数据统计

---

### 8. 级联功能

**需求**:
- 作为下级平台向上级平台注册
- 转发下级设备到上级
- 级联查询和控制

---

### 9. 设备分组管理

**需求**:
- 设备分组
- 批量控制
- 分组权限管理

---

## 📋 开发优先级建议

| 优先级 | 任务 | 预计工作量 | 依赖 |
|--------|------|------------|------|
| P0 | 设备配置扩展完整实现 | 3-4天 | 无 |
| P0 | SUBSCRIBE/NOTIFY 完整实现 | 5-7天 | C扩展 SUBSCRIBE 支持 |
| P0 | 目录定时查询 | 1天 | 无 |
| P1 | 2022 新增命令支持 | 3-4天 | 无 |
| P1 | 语音对讲业务层实现 | 4-5天 | C扩展音频支持 |
| P2 | 录像回放增强 | 2-3天 | 无 |
| P3 | 设备状态监控优化 | 2-3天 | 无 |
| P3 | 级联功能 | 7-10天 | 基础功能稳定 |
| P3 | 设备分组管理 | 3-4天 | 无 |

**总计**: 约 30-45 个工作日

---

## 🧪 测试

**单元测试目录**: `tests/`

需要新增的测试文件:
- `test_device_config.php` - 设备配置测试
- `test_subscribe.php` - 订阅功能测试
- `test_2022_commands.php` - 2022 版命令测试
- `test_audio_talk.php` - 语音对讲测试

**集成测试**:
```bash
cd /Users/jiechengyang/src/c-app/php-exosip/examples/gbvr-iot
php tests/test_gb28181_integration.php
```

---

## 📚 相关资源

- **项目文档**: [07-开发指南/README.md](../07-开发指南/README.md)
- **Webman 文档**: https://www.workerman.net/doc/webman/
- **GB28181 标准**: GB/T 28181-2016 和 GB/T 28181-2022
- **ZLMediaKit**: https://github.com/ZLMediaKit/ZLMediaKit
