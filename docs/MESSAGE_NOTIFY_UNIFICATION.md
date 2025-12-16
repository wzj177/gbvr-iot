# MESSAGE 和 NOTIFY 统一处理架构 + 移动设备位置订阅机制

## 概述

本文档说明了 GB28181Handler 中 MESSAGE 和 NOTIFY 处理逻辑的统一化改造，以及移动设备位置订阅（MobilePosition）的正确实现。

## 重要更正：MobilePosition 订阅机制

### ❌ 错误理解（已修正）
之前误认为 MobilePosition 是通过 MESSAGE 查询/响应实现的。

### ✅ 正确实现
MobilePosition 使用 **SUBSCRIBE/NOTIFY** 订阅机制：

```
平台 → SUBSCRIBE (Event: presence, Expires: 3600)
     ← 200 OK
     ← NOTIFY (Event: presence, 包含位置信息)
     → 200 OK
     ← NOTIFY...
     → 200 OK...
     ...（周期性通知）

平台 → SUBSCRIBE (刷新订阅)
     ← 200 OK
     ← NOTIFY...

平台 → SUBSCRIBE (Expires: 0, 取消订阅)
     ← 200 OK
     ← NOTIFY (Subscription-State: terminated, 最后一次通知)
     → 200 OK
```

## MobilePosition 订阅详细流程

### 1. 订阅请求（SUBSCRIBE）

**平台发送 SUBSCRIBE：**
```
SUBSCRIBE sip:34020000001320000001@192.168.1.200:5060 SIP/2.0
Via: SIP/2.0/UDP 192.168.1.100:5060;rport;branch=z9hG4bKsub123
From: <sip:34020000002000000001@192.168.1.100:5060>;tag=sub123
To: <sip:34020000001320000001@192.168.1.200:5060>
Call-ID: sub-123456@192.168.1.100
CSeq: 1 SUBSCRIBE
Event: presence
Expires: 3600
Content-Type: application/xml
Content-Length: [length]

<?xml version="1.0" encoding="GB2312"?>
<Query>
    <CmdType>MobilePosition</CmdType>
    <SN>123</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <Interval>60</Interval> <!-- 可选：上报间隔（秒） -->
</Query>
```

**关键字段：**
- `Event: presence` - 移动设备位置订阅的固定事件类型
- `Expires: 3600` - 订阅有效期（秒），0 表示取消订阅
- XML Body - 包含订阅参数（如上报间隔）

### 2. 设备响应

**正常接受（200 OK）：**
```
SIP/2.0 200 OK
Via: SIP/2.0/UDP 192.168.1.100:5060;rport=5060;branch=z9hG4bKsub123
From: <sip:34020000002000000001@192.168.1.100:5060>;tag=sub123
To: <sip:34020000001320000001@192.168.1.200:5060>;tag=dev123
Call-ID: sub-123456@192.168.1.100
CSeq: 1 SUBSCRIBE
Expires: 3600
Content-Length: 0
```

**订阅时间太短（423 Interval Too Small）：**
```
SIP/2.0 423 Interval Too Small
Min-Expires: 60
Content-Length: 0
```

### 3. 位置通知（NOTIFY）

**设备发送 NOTIFY：**
```
NOTIFY sip:34020000002000000001@192.168.1.100:5060 SIP/2.0
Via: SIP/2.0/UDP 192.168.1.200:5060;rport;branch=z9hG4bKnotify123
From: <sip:34020000001320000001@192.168.1.200:5060>;tag=dev123
To: <sip:34020000002000000001@192.168.1.100:5060>;tag=sub123
Call-ID: sub-123456@192.168.1.100
CSeq: 1 NOTIFY
Event: presence
Subscription-State: active;expires=3540
Content-Type: application/xml
Content-Length: [length]

<?xml version="1.0" encoding="GB2312"?>
<Notify>
    <CmdType>MobilePosition</CmdType>
    <SN>124</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <Time>2024-01-15T10:30:00</Time>
    <Longitude>120.123456</Longitude>
    <Latitude>30.654321</Latitude>
    <Speed>35.5</Speed>
    <Direction>90.0</Direction>
    <Altitude>100.0</Altitude>
</Notify>
```

**关键字段：**
- `Event: presence` - 必须与 SUBSCRIBE 中的 Event 匹配
- `Subscription-State` - 订阅状态：
  - `active` - 订阅活跃，包含 `expires` 参数（剩余时间）
  - `pending` - 订阅待确认
  - `terminated` - 订阅终止（取消订阅时的最后通知）

### 4. 刷新订阅

**平台在过期前刷新：**
```
SUBSCRIBE ...
Event: presence
Expires: 3600  <!-- 重新设置有效期 -->
```

设备回复 200 OK，继续发送 NOTIFY。

### 5. 取消订阅

**平台发送 Expires: 0：**
```
SUBSCRIBE ...
Event: presence
Expires: 0  <!-- 0 表示取消 -->
```

**设备回复并发送最后通知：**
```
200 OK
Expires: 0

NOTIFY ...
Subscription-State: terminated;reason=timeout
```

## 改造目的

在改造前，存在两个问题：
1. `handleMessage()` 和 `handleNotify()` 使用不同的处理模式
   - `handleMessage()` 使用 MessageHandler + CommandType 模式（架构清晰）
   - `handleNotify()` 使用内联 XML 解析（代码重复、难以维护）

2. **MobilePosition 实现错误**
   - 误认为是 MESSAGE 查询/响应模式
   - 实际应使用 SUBSCRIBE/NOTIFY 订阅机制

改造后：
- 统一为 **MessageHandler 模式**
- 正确实现 **SUBSCRIBE/NOTIFY** 订阅机制
- 提高代码一致性和可维护性

## 架构改造

### 1. 统一 MESSAGE 和 NOTIFY 处理

```php
// MESSAGE 和 NOTIFY 现在使用相同的 XML 解析模式
public function handleMessage(\SipEvent $event): void {
    $xml = @simplexml_load_string($body);
    $result = $this->messageHandler->handle($xml, $deviceId, $options);
    $this->dispatchCommand($event, $deviceId, $result);
}

public function handleNotify(\SipEvent $event): void {
    // 优先检查订阅事件（Event 头域）
    if ($eventType === 'presence') {
        $this->handleMobilePositionNotify(...);
        return;
    }
    
    // 处理 XML 命令通知（CmdType）
    $xml = @simplexml_load_string($body);
    $result = $this->messageHandler->handle($xml, $deviceId, $options);
    $this->dispatchCommand($event, $deviceId, $result);
}
```

### 2. SUBSCRIBE/NOTIFY 订阅机制

```php
/**
 * 处理订阅请求
 */
public function handleSubscribe(\SipEvent $event): void {
    $eventType = $event->getHeader('Event');
    $expires = $event->getExpires();
    
    if ($eventType === 'presence') {
        // 移动设备位置订阅
        $this->handleMobilePositionSubscribe($event, $deviceId, $expires, $body);
    }
}

/**
 * 处理位置订阅
 */
private function handleMobilePositionSubscribe(...): void {
    // 1. 验证 Expires（检查是否太小）
    if ($expires > 0 && $expires < $minExpires) {
        // 返回 423 Interval Too Small
        $this->sipServer->sendResponse($tid, 423, 'Interval Too Small', [
            'Min-Expires' => $minExpires
        ]);
        return;
    }
    
    // 2. 保存订阅信息
    $subscription = [
        'device_id' => $deviceId,
        'event' => 'presence',
        'expires' => $expires,
        'expire_time' => time() + $expires,
        'interval' => $interval,  // 从 XML Body 解析
    ];
    $this->deviceManager->addSubscription($deviceId, 'mobile_position', $subscription);
    
    // 3. 返回 200 OK
    $this->sipServer->sendResponse($tid, 200, 'OK', ['Expires' => $expires]);
}

/**
 * 处理位置通知
 */
private function handleMobilePositionNotify(...): void {
    // 1. 检查订阅状态
    $isTerminated = stripos($subscriptionState, 'terminated') !== false;
    if ($isTerminated) {
        $this->deviceManager->removeSubscription($deviceId, 'mobile_position');
    }
    
    // 2. 解析位置信息（使用 MobilePositionCommand）
    $result = $this->messageHandler->handle($xml, $deviceId, $options);
    
    // 3. 推送到业务系统
    $this->postTask('mobile_position', [
        'device_id' => $deviceId,
        'longitude' => $result['longitude'],
        'latitude' => $result['latitude'],
        // ...
        'subscription_state' => $subscriptionState,
        'is_terminated' => $isTerminated,
    ]);
    
    // 4. 返回 200 OK
    $this->sipServer->sendResponse($tid, 200, 'OK');
}
```

### 3. 订阅管理（DeviceManager）

```php
class DeviceManager {
    /**
     * 添加订阅
     */
    public function addSubscription(string $deviceId, string $type, array $subscription): void {
        $device->subscriptions[$type] = $subscription;
    }
    
    /**
     * 移除订阅
     */
    public function removeSubscription(string $deviceId, string $type): void {
        unset($device->subscriptions[$type]);
    }
    
    /**
     * 获取订阅
     */
    public function getSubscription(string $deviceId, string $type): ?array {
        return $device->subscriptions[$type] ?? null;
    }
    
    /**
     * 检查订阅是否过期
     */
    public function isSubscriptionExpired(string $deviceId, string $type): bool {
        $subscription = $this->getSubscription($deviceId, $type);
        return time() >= ($subscription['expire_time'] ?? 0);
    }
}
```

### 2. 新增 MediaStatusCommand

创建 `MediaStatusCommand` 类处理 GB28181-2022 的 MediaStatus 通知：

```php
namespace Gb28181\GateWay\Message\CommandType;

class MediaStatusCommand implements CommandInterface
{
    public function getCommandType(): string
    {
        return 'MediaStatus';
    }

    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): mixed
    {
        $notifyType = (string)($xml->NotifyType ?? '');
        
        $data = [
            'device_id' => $deviceId,
            'cmd_type' => 'MediaStatus',
            'notify_type' => $notifyType,
            'session_id' => (string)($xml->SessionID ?? ''),
        ];

        // 根据通知类型提取不同字段
        if ($notifyType === 'SnapshotComplete') {
            $data['file_url'] = (string)($xml->FileURL ?? '');
        } elseif ($notifyType === 'Keepalive') {
            $data['ssrc'] = (string)($xml->SSRC ?? '');
            $data['bit_rate'] = (string)($xml->BitRate ?? '');
            $data['frame_rate'] = (string)($xml->FrameRate ?? '');
            // ...
        }

        return $data;
    }
}
```

### 3. 注册新命令

在 GB28181Handler 构造函数中注册：

```php
$this->messageHandler->registerCommand(new MediaStatusCommand());
```

### 4. 添加分发逻辑

在 `dispatchCommand()` 中添加 MediaStatus 处理：

```php
switch ($cmdType) {
    case 'MediaStatus':
        $this->handleMediaStatusReport($event, $deviceId, $result);
        break;
    // ...
}
```

### 5. 适配处理方法

重命名并适配原有方法：

```php
// 旧方法签名
private function handleMediaStatus(\SipEvent $event, string $deviceId, array $data): void

// 新方法签名（统一格式）
private function handleMediaStatusReport(\SipEvent $event, string $deviceId, array $result): void
```

## 支持的命令类型

### MESSAGE 命令（查询/响应）

| 命令类型 | CommandType 类 | 用途 |
|---------|---------------|------|
| Keepalive | KeepaliveCommand | 设备心跳保活 |
| Catalog | CatalogCommand | 设备目录查询 |
| DeviceInfo | DeviceInfoCommand | 设备信息查询 |
| DeviceStatus | DeviceStatusCommand | 设备状态查询 |
| Alarm | AlarmCommand | 报警信息上报 |
| MobilePosition | MobilePositionCommand | 位置查询/上报 |

### NOTIFY 命令（异步通知）

| 命令类型 | CommandType 类 | 用途 |
|---------|---------------|------|
| MediaStatus | MediaStatusCommand | 媒体状态通知（GB28181-2022）|
| MobilePosition | MobilePositionCommand | 位置主动上报 |

## MobilePosition 双路径支持

`MobilePosition` 支持通过 **MESSAGE** 和 **NOTIFY** 两种方式：

### MESSAGE 方式（查询/响应）
1. 平台发送 MobilePosition 查询（通过 `Gb28181Service::queryMobilePosition()`）
2. 设备通过 MESSAGE 响应位置信息
3. 调用 `handleMobilePositionReport()` 处理

### NOTIFY 方式（主动上报）
1. 设备主动发送 NOTIFY 包含 MobilePosition
2. 通过 MessageHandler 解析（复用 MobilePositionCommand）
3. 调用 `handleMobilePositionReport()` 处理

**统一处理方法：**
```php
private function handleMobilePositionReport(\SipEvent $event, string $deviceId, array $result): void
{
    // 同时处理 MESSAGE 和 NOTIFY 的位置上报
    $longitude = $result['longitude'] ?? 0;
    $latitude = $result['latitude'] ?? 0;
    // ...
}
```

## MediaStatus 通知类型

### SnapshotComplete（截图完成）

设备完成图像抓拍后的通知：

```xml
<?xml version="1.0" encoding="GB2312"?>
<Notify>
    <CmdType>MediaStatus</CmdType>
    <SN>123</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <NotifyType>SnapshotComplete</NotifyType>
    <SessionID>session_123</SessionID>
    <FileURL>http://192.168.1.100/snapshot.jpg</FileURL>
</Notify>
```

### Keepalive（媒体流保活）

设备发送媒体流状态信息：

```xml
<?xml version="1.0" encoding="GB2312"?>
<Notify>
    <CmdType>MediaStatus</CmdType>
    <SN>124</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <NotifyType>Keepalive</NotifyType>
    <SessionID>session_123</SessionID>
    <SSRC>0123456789</SSRC>
    <BitRate>2048</BitRate>
    <FrameRate>25</FrameRate>
    <Resolution>1920x1080</Resolution>
    <PacketLoss>0.1</PacketLoss>
</Notify>
```

## 代码删除说明

### 删除的方法
- `handleMobilePosition()` - 旧的 MobilePosition 处理方法（已被 `handleMobilePositionReport()` 取代）

### 修改的方法
- `handleMediaStatus()` → `handleMediaStatusReport()` - 统一命名规范，适配新的参数格式

## 改造优势

1. **代码一致性**：MESSAGE 和 NOTIFY 使用相同的处理模式
2. **易于扩展**：新增命令类型只需创建 CommandType 类并注册
3. **减少重复**：消除内联 XML 解析的重复代码
4. **统一分发**：所有命令通过 `dispatchCommand()` 统一分发
5. **更好的错误处理**：通过 try-catch 统一处理未知命令类型

## 测试建议

1. **MESSAGE 路径测试**：
   - 查询设备目录、信息、状态
   - 查询移动设备位置
   - 接收报警信息

2. **NOTIFY 路径测试**：
   - 接收 MediaStatus (SnapshotComplete)
   - 接收 MediaStatus (Keepalive)
   - 接收 MobilePosition 主动上报

3. **错误处理测试**：
   - 发送未知 CmdType
   - 发送格式错误的 XML
   - 测试异常情况下的响应

## 相关文件

- [MediaStatusCommand.php](../src/Message/CommandType/MediaStatusCommand.php) - 新增命令类
- [GB28181Handler.php](../src/Handlers/GB28181Handler.php) - 主处理类（已重构）
- [MobilePositionCommand.php](../src/Message/CommandType/MobilePositionCommand.php) - 位置命令类（支持双路径）
- [MessageHandler.php](../src/Message/MessageHandler.php) - 消息处理调度器

## 更新日期

2024-01-XX
