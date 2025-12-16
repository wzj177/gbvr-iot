# MobilePosition 订阅机制修正

## 问题说明

之前的实现错误地将 MobilePosition 理解为 MESSAGE 查询/响应模式，实际上 GB28181 标准中移动设备位置订阅使用的是 **SUBSCRIBE/NOTIFY** 机制。

## 正确的订阅流程

### 1. 初始订阅

```
平台 → SUBSCRIBE (Event: presence, Expires: 3600)
     ← 200 OK
     ← NOTIFY (Event: presence, 位置信息)
     → 200 OK
     ← NOTIFY (周期性上报)
     → 200 OK
     ... (持续订阅期间)
```

### 2. 刷新订阅

```
平台 → SUBSCRIBE (Event: presence, Expires: 3600)  [在过期前发送]
     ← 200 OK
     ← NOTIFY (继续上报)
     → 200 OK
```

### 3. 取消订阅

```
平台 → SUBSCRIBE (Event: presence, Expires: 0)
     ← 200 OK
     ← NOTIFY (Subscription-State: terminated, 最后一次位置)
     → 200 OK
     [设备停止上报]
```

## SUBSCRIBE 消息格式

### 订阅请求

```
SUBSCRIBE sip:34020000001320000001@192.168.1.200:5060 SIP/2.0
Via: SIP/2.0/UDP 192.168.1.100:5060;rport;branch=z9hG4bKsub123
From: <sip:34020000002000000001@192.168.1.100:5060>;tag=sub123
To: <sip:34020000001320000001@192.168.1.200:5060>
Call-ID: sub-123456@192.168.1.100
CSeq: 1 SUBSCRIBE
Event: presence                    ← 关键：固定为 presence
Expires: 3600                      ← 订阅有效期（秒），0=取消
Content-Type: application/xml
Content-Length: [length]

<?xml version="1.0" encoding="GB2312"?>
<Query>
    <CmdType>MobilePosition</CmdType>
    <SN>123</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <Interval>60</Interval>        ← 可选：上报间隔（秒）
</Query>
```

### 关键字段说明

| 字段 | 必填 | 说明 |
|------|------|------|
| Event | ✅ | 固定为 `presence`，标识位置订阅 |
| Expires | ✅ | 订阅时长（秒），建议 60-3600，0 表示取消订阅 |
| Interval | ⚠️ | 位置上报间隔（秒），可选，设备可自行决定 |

## NOTIFY 消息格式

### 位置通知

```
NOTIFY sip:34020000002000000001@192.168.1.100:5060 SIP/2.0
Via: SIP/2.0/UDP 192.168.1.200:5060;rport;branch=z9hG4bKnotify123
From: <sip:34020000001320000001@192.168.1.200:5060>;tag=dev123
To: <sip:34020000002000000001@192.168.1.100:5060>;tag=sub123
Call-ID: sub-123456@192.168.1.100
CSeq: 1 NOTIFY
Event: presence                                    ← 与 SUBSCRIBE 匹配
Subscription-State: active;expires=3540            ← 订阅状态
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

### Subscription-State 说明

| 状态 | 说明 | expires 参数 |
|------|------|-------------|
| active | 订阅活跃，正常上报 | 必须，剩余时间（秒） |
| pending | 订阅待确认 | 必须 |
| terminated | 订阅终止，停止上报 | 可选，reason 参数说明原因 |

**终止原因示例：**
- `terminated;reason=timeout` - 订阅超时
- `terminated;reason=deactivated` - 平台取消订阅（Expires: 0）
- `terminated;reason=noresource` - 设备资源不足

## 设备响应

### 正常接受（200 OK）

```
SIP/2.0 200 OK
Via: SIP/2.0/UDP 192.168.1.100:5060;rport=5060;branch=z9hG4bKsub123
From: <sip:34020000002000000001@192.168.1.100:5060>;tag=sub123
To: <sip:34020000001320000001@192.168.1.200:5060>;tag=dev123
Call-ID: sub-123456@192.168.1.100
CSeq: 1 SUBSCRIBE
Expires: 3600                      ← 确认订阅时长
Content-Length: 0
```

### 订阅时间太短（423 Interval Too Small）

```
SIP/2.0 423 Interval Too Small
Via: SIP/2.0/UDP 192.168.1.100:5060;rport=5060;branch=z9hG4bKsub123
From: <sip:34020000002000000001@192.168.1.100:5060>;tag=sub123
To: <sip:34020000001320000001@192.168.1.200:5060>;tag=dev123
Call-ID: sub-123456@192.168.1.100
CSeq: 1 SUBSCRIBE
Min-Expires: 60                    ← 设备要求的最小订阅时长
Content-Length: 0
```

**处理建议：**
- 检查 `Min-Expires` 值
- 使用不小于 `Min-Expires` 的值重新订阅
- 一般设备最小值为 60 秒

## 代码修改

### 1. Service 层（Gb28181Service.php）

**修改前（错误）：**
```php
public function queryMobilePosition(string $deviceId, ?int $interval = null): bool
{
    // 错误：使用 MESSAGE 查询
    return $this->getGb28181Client()->queryMobilePosition($deviceId, $interval);
}
```

**修改后（正确）：**
```php
/**
 * 订阅移动设备位置 (SUBSCRIBE/NOTIFY 机制)
 */
public function subscribeMobilePosition(string $deviceId, int $expires = 3600, ?int $interval = null): bool
{
    return $this->getGb28181Client()->subscribeMobilePosition($deviceId, $expires, $interval);
}

/**
 * 取消移动设备位置订阅
 */
public function unsubscribeMobilePosition(string $deviceId): bool
{
    return $this->getGb28181Client()->unsubscribeMobilePosition($deviceId);
}
```

### 2. Handler 层（GB28181Handler.php）

**新增方法：**
- `handleSubscribe()` - 处理订阅请求，检查 Event 类型
- `handleMobilePositionSubscribe()` - 处理位置订阅逻辑
  - 验证 Expires（检查是否太小）
  - 保存订阅信息到 DeviceManager
  - 返回 200 OK 或 423 Interval Too Small
- `handleMobilePositionNotify()` - 处理位置通知
  - 检查 Subscription-State（active/terminated）
  - 解析位置信息
  - 推送到业务系统
  - 如果 terminated，删除订阅记录

**修改 handleNotify()：**
```php
public function handleNotify(\SipEvent $event): void {
    $eventType = $event->getHeader('Event');
    
    // 优先处理订阅事件通知
    if ($eventType === 'presence') {
        $this->handleMobilePositionNotify(...);
        return;
    }
    
    // 其他 XML 命令通知
    // ...
}
```

### 3. DeviceManager 订阅管理

**新增方法：**
```php
// 添加订阅
public function addSubscription(string $deviceId, string $type, array $subscription): void

// 移除订阅
public function removeSubscription(string $deviceId, string $type): void

// 获取订阅
public function getSubscription(string $deviceId, string $type): ?array

// 检查订阅是否过期
public function isSubscriptionExpired(string $deviceId, string $type): bool
```

**订阅数据结构：**
```php
$subscription = [
    'device_id' => $deviceId,
    'type' => 'mobile_position',
    'event' => 'presence',
    'call_id' => $callId,
    'expires' => 3600,
    'expire_time' => time() + 3600,
    'interval' => 60,  // 可选
    'created_at' => time(),
];
```

### 4. 测试工具（GB28181Test.php）

**菜单项修改：**
- 14. ~~查询设备位置~~ → **订阅设备位置 (MobilePosition)**
- 15. **取消位置订阅**（新增）

**新增方法：**
- `handleMobilePositionSubscription()` - 订阅位置测试
- `handleUnsubscribeMobilePosition()` - 取消订阅测试

## 使用示例

### PHP 代码

```php
use CoreW\Business\GB\Gb28181Service;

$gb28181Service = new Gb28181Service($bfw);

// 1. 订阅位置（3600秒，每60秒上报一次）
$result = $gb28181Service->subscribeMobilePosition(
    deviceId: '34020000001320000001',
    expires: 3600,
    interval: 60
);

// 2. 刷新订阅（在过期前再次调用）
sleep(3000);
$result = $gb28181Service->subscribeMobilePosition(
    deviceId: '34020000001320000001',
    expires: 3600,
    interval: 60
);

// 3. 取消订阅
$result = $gb28181Service->unsubscribeMobilePosition(
    deviceId: '34020000001320000001'
);
```

### 测试工具

```bash
# 启动测试工具
php bin/command.php gb:test

# 选择菜单项
14. 订阅设备位置 (MobilePosition)
  → 输入设备ID
  → 输入订阅时长（60-3600秒）
  → 输入上报间隔（可选）
  → 发送 SUBSCRIBE 请求

# 取消订阅
15. 取消位置订阅
  → 输入设备ID
  → 发送 SUBSCRIBE (Expires: 0)
```

## Hook 回调数据格式

### mobile_position_subscribe（订阅成功）

```json
{
    "event": "mobile_position_subscribe",
    "device_id": "34020000001320000001",
    "expires": 3600,
    "interval": 60,
    "call_id": "sub-123456@192.168.1.100",
    "timestamp": 1705288200
}
```

### mobile_position（位置通知）

```json
{
    "event": "mobile_position",
    "device_id": "34020000001320000001",
    "longitude": 120.123456,
    "latitude": 30.654321,
    "speed": 35.5,
    "direction": 90.0,
    "altitude": 100.0,
    "time": "2024-01-15T10:30:00",
    "subscription_state": "active;expires=3540",
    "is_terminated": false,
    "timestamp": 1705288200
}
```

### mobile_position_unsubscribe（取消订阅）

```json
{
    "event": "mobile_position_unsubscribe",
    "device_id": "34020000001320000001",
    "call_id": "sub-123456@192.168.1.100",
    "timestamp": 1705288200
}
```

## 注意事项

### 1. 订阅管理

- **过期时间**：建议 60-3600 秒，太短会被设备拒绝（423）
- **刷新机制**：在过期前重新发送 SUBSCRIBE（建议提前 60 秒）
- **取消订阅**：必须发送 Expires: 0，设备才会停止上报

### 2. 设备要求

- 设备可能设置最小订阅时长（Min-Expires）
- 收到 423 响应后，需使用不小于 Min-Expires 的值重新订阅
- 部分设备可能不支持 Interval 参数，会自行决定上报频率

### 3. NOTIFY 超时处理

- 如果设备 NOTIFY 请求超时（无法到达平台），应自动移除订阅
- 平台需要监控订阅状态，及时清理过期订阅

### 4. 并发订阅

- 同一设备可能有多个订阅（不同 Call-ID）
- 需要根据 Call-ID 区分不同的订阅会话
- 取消订阅时需要使用正确的 Call-ID

## 相关文档

- [MESSAGE_NOTIFY_UNIFICATION.md](MESSAGE_NOTIFY_UNIFICATION.md) - MESSAGE 和 NOTIFY 统一架构
- [GB28181_COMMAND_EXAMPLES.md](GB28181_COMMAND_EXAMPLES.md) - 完整命令示例
- GB/T 28181-2016 标准 - 第 9.4.2 节：移动设备位置订阅
- GB/T 28181-2022 标准 - 第 9.4.2 节：移动设备位置订阅（增强）

## 修改文件清单

1. ✅ [GB28181Handler.php](../src/Handlers/GB28181Handler.php)
   - handleSubscribe() - 处理订阅请求
   - handleMobilePositionSubscribe() - 位置订阅逻辑
   - handleMobilePositionNotify() - 位置通知处理
   - handleNotify() - 优先检查 Event 头域

2. ✅ [DeviceManager.php](../src/Device/DeviceManager.php)
   - addSubscription() - 添加订阅
   - removeSubscription() - 移除订阅
   - getSubscription() - 获取订阅
   - isSubscriptionExpired() - 检查过期

3. ✅ [Gb28181Service.php](../../gbvr-iot/CoreW/Business/GB/Gb28181Service.php)
   - subscribeMobilePosition() - 订阅位置
   - unsubscribeMobilePosition() - 取消订阅

4. ✅ [GB28181Test.php](../../gbvr-iot/app/command/GB28181Test.php)
   - handleMobilePositionSubscription() - 订阅测试
   - handleUnsubscribeMobilePosition() - 取消订阅测试

## 更新日期

2025-12-15
