# GB28181 命令完整示例

## 概述

本文档提供 GB28181 协议中所有命令的完整使用示例，包括查询、控制和通知。

## 1. 设备注册与心跳

### 1.1 设备注册

设备主动发起 REGISTER：

```
REGISTER sip:34020000002000000001@192.168.1.100:5060 SIP/2.0
Via: SIP/2.0/UDP 192.168.1.200:5060;rport;branch=z9hG4bK123456
From: <sip:34020000001320000001@192.168.1.200:5060>;tag=abc123
To: <sip:34020000001320000001@192.168.1.100:5060>
Call-ID: reg-123456@192.168.1.200
CSeq: 1 REGISTER
Contact: <sip:34020000001320000001@192.168.1.200:5060>
Max-Forwards: 70
Expires: 3600
Content-Length: 0
```

平台响应 200 OK，设备注册成功。

### 1.2 设备心跳

设备通过 MESSAGE 发送心跳：

```xml
<?xml version="1.0" encoding="GB2312"?>
<Notify>
    <CmdType>Keepalive</CmdType>
    <SN>12345</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <Status>OK</Status>
</Notify>
```

**PHP 使用示例：**
```php
// 心跳自动处理，无需手动调用
// handleKeepalive() 会更新设备在线状态
```

## 2. 设备信息查询

### 2.1 查询设备目录（Catalog）

**服务层调用：**
```php
use Gb28181\GateWay\Services\Gb28181Service;

$service = new Gb28181Service($config);
$service->queryCatalog('34020000001320000001');
```

**XML 请求体：**
```xml
<?xml version="1.0" encoding="GB2312"?>
<Query>
    <CmdType>Catalog</CmdType>
    <SN>12346</SN>
    <DeviceID>34020000001320000001</DeviceID>
</Query>
```

**设备响应：**
```xml
<?xml version="1.0" encoding="GB2312"?>
<Response>
    <CmdType>Catalog</CmdType>
    <SN>12346</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <SumNum>2</SumNum>
    <DeviceList Num="2">
        <Item>
            <DeviceID>34020000001320000001</DeviceID>
            <Name>摄像头1</Name>
            <Manufacturer>HikVision</Manufacturer>
            <Model>DS-2CD2035</Model>
            <Owner>Admin</Owner>
            <CivilCode>340200</CivilCode>
            <Address>测试地址</Address>
            <Parental>0</Parental>
            <ParentID></ParentID>
            <SafetyWay>0</SafetyWay>
            <RegisterWay>1</RegisterWay>
            <Secrecy>0</Secrecy>
            <Status>ON</Status>
        </Item>
        <Item>
            <DeviceID>34020000001320000002</DeviceID>
            <Name>摄像头2</Name>
            <!-- ... -->
        </Item>
    </DeviceList>
</Response>
```

### 2.2 查询设备信息（DeviceInfo）

**服务层调用：**
```php
$service->queryDeviceInfo('34020000001320000001');
```

**设备响应：**
```xml
<?xml version="1.0" encoding="GB2312"?>
<Response>
    <CmdType>DeviceInfo</CmdType>
    <SN>12347</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <DeviceName>IPC-HK001</DeviceName>
    <Manufacturer>HikVision</Manufacturer>
    <Model>DS-2CD2035</Model>
    <Firmware>V5.5.0</Firmware>
    <Channel>1</Channel>
</Response>
```

### 2.3 查询设备状态（DeviceStatus）

**服务层调用：**
```php
$service->queryDeviceStatus('34020000001320000001');
```

**设备响应：**
```xml
<?xml version="1.0" encoding="GB2312"?>
<Response>
    <CmdType>DeviceStatus</CmdType>
    <SN>12348</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <Online>ONLINE</Online>
    <Status>OK</Status>
    <Encode>ON</Encode>
    <Record>OFF</Record>
</Response>
```

## 3. 移动设备位置（MobilePosition）

### 3.1 查询位置（MESSAGE 方式）

**服务层调用：**
```php
// 单次查询
$service->queryMobilePosition('34020000001320000001');

// 周期查询（每60秒上报一次）
$service->queryMobilePosition('34020000001320000001', 60);
```

**XML 请求体：**
```xml
<?xml version="1.0" encoding="GB2312"?>
<Query>
    <CmdType>MobilePosition</CmdType>
    <SN>12349</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <Interval>60</Interval> <!-- 可选，单位：秒 -->
</Query>
```

**设备响应（MESSAGE）：**
```xml
<?xml version="1.0" encoding="GB2312"?>
<Response>
    <CmdType>MobilePosition</CmdType>
    <SN>12349</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <Time>2024-01-15T10:30:00</Time>
    <Longitude>120.123456</Longitude>
    <Latitude>30.654321</Latitude>
    <Speed>35.5</Speed>
    <Direction>90.0</Direction>
    <Altitude>100.0</Altitude>
</Response>
```

### 3.2 主动上报（NOTIFY 方式）

设备主动通过 NOTIFY 发送位置：

```xml
<?xml version="1.0" encoding="GB2312"?>
<Notify>
    <CmdType>MobilePosition</CmdType>
    <SN>12350</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <Time>2024-01-15T10:31:00</Time>
    <Longitude>120.124567</Longitude>
    <Latitude>30.655432</Latitude>
    <Speed>40.0</Speed>
    <Direction>92.0</Direction>
    <Altitude>105.0</Altitude>
</Notify>
```

**处理流程：**
```
设备 → NOTIFY (MobilePosition) → GB28181Handler
     → MessageHandler.handle()
     → MobilePositionCommand.handle()
     → dispatchCommand()
     → handleMobilePositionReport()
     → postTask('mobile_position') → 业务系统
```

## 4. PTZ 云台控制

### 4.1 方向控制

**服务层调用：**
```php
// 向上
$service->ptzControl('34020000001320000001', '34020000001320000001', 'up', 5);

// 向下
$service->ptzControl('34020000001320000001', '34020000001320000001', 'down', 5);

// 向左
$service->ptzControl('34020000001320000001', '34020000001320000001', 'left', 5);

// 向右
$service->ptzControl('34020000001320000001', '34020000001320000001', 'right', 5);
```

### 4.2 缩放控制

**服务层调用：**
```php
// 放大
$service->ptzControl('34020000001320000001', '34020000001320000001', 'zoom_in', 5);

// 缩小
$service->ptzControl('34020000001320000001', '34020000001320000001', 'zoom_out', 5);
```

### 4.3 停止控制

**服务层调用：**
```php
$service->ptzStop('34020000001320000001', '34020000001320000001');
```

**前端使用模式（推荐）：**
```javascript
// 前端通过鼠标事件控制 PTZ
const ptzControl = {
    // 鼠标按下 - 开始移动
    onMouseDown(direction, speed) {
        fetch('/api/gb28181/ptz/control', {
            method: 'POST',
            body: JSON.stringify({
                device_id: '34020000001320000001',
                channel_id: '34020000001320000001',
                command: direction,  // 'up', 'down', 'left', 'right'
                speed: speed         // 1-255
            })
        });
    },
    
    // 鼠标释放 - 停止移动
    onMouseUp() {
        fetch('/api/gb28181/ptz/stop', {
            method: 'POST',
            body: JSON.stringify({
                device_id: '34020000001320000001',
                channel_id: '34020000001320000001'
            })
        });
    }
};

// 绑定事件
document.getElementById('btn-up').addEventListener('mousedown', () => {
    ptzControl.onMouseDown('up', 5);
});

document.getElementById('btn-up').addEventListener('mouseup', () => {
    ptzControl.onMouseUp();
});
```

**PTZ 控制字节格式：**
```
A5 0F 01 [direction] [speed1] [speed2] [zoom] [checksum]

direction:
- 08: 上
- 04: 下
- 02: 左
- 01: 右
- 00: 停止

speed1/speed2: 0x00-0xFF (0-255)
zoom: 
- 10: 放大
- 20: 缩小
- 00: 无操作
```

## 5. 视频点播

### 5.1 发起点播

**服务层调用：**
```php
$streamInfo = $service->startPlay(
    deviceId: '34020000001320000001',
    channelId: '34020000001320000001',
    mediaServerId: 'zlm-server-1'
);

// 返回结果：
// [
//     'stream_id' => 'stream_123',
//     'ssrc' => '0123456789',
//     'play_url' => 'http://192.168.1.100:8080/live/stream_123.flv'
// ]
```

**INVITE 请求（平台发起）：**
```
INVITE sip:34020000001320000001@192.168.1.200:5060 SIP/2.0
Via: SIP/2.0/UDP 192.168.1.100:5060;rport;branch=z9hG4bKinvite123
From: <sip:34020000002000000001@192.168.1.100:5060>;tag=inv123
To: <sip:34020000001320000001@192.168.1.200:5060>
Call-ID: invite-123456@192.168.1.100
CSeq: 1 INVITE
Contact: <sip:34020000002000000001@192.168.1.100:5060>
Content-Type: application/sdp
Subject: 34020000001320000001:0123456789,34020000002000000001:0
Content-Length: [length]

v=0
o=34020000002000000001 0 0 IN IP4 192.168.1.100
s=Play
c=IN IP4 192.168.1.100
t=0 0
m=video 10000 RTP/AVP 96
a=rtpmap:96 PS/90000
a=recvonly
a=setup:passive
a=connection:new
y=0123456789
```

**设备响应 200 OK：**
```
SIP/2.0 200 OK
Via: SIP/2.0/UDP 192.168.1.100:5060;rport=5060;branch=z9hG4bKinvite123
From: <sip:34020000002000000001@192.168.1.100:5060>;tag=inv123
To: <sip:34020000001320000001@192.168.1.200:5060>;tag=dev123
Call-ID: invite-123456@192.168.1.100
CSeq: 1 INVITE
Contact: <sip:34020000001320000001@192.168.1.200:5060>
Content-Type: application/sdp
Content-Length: [length]

v=0
o=34020000001320000001 0 0 IN IP4 192.168.1.200
s=Play
c=IN IP4 192.168.1.200
t=0 0
m=video 6000 RTP/AVP 96
a=rtpmap:96 PS/90000
a=sendonly
y=0123456789
```

### 5.2 停止点播

**服务层调用：**
```php
$service->stopPlay(
    deviceId: '34020000001320000001',
    channelId: '34020000001320000001',
    streamId: 'stream_123'
);
```

**BYE 请求：**
```
BYE sip:34020000001320000001@192.168.1.200:5060 SIP/2.0
Via: SIP/2.0/UDP 192.168.1.100:5060;rport;branch=z9hG4bKbye123
From: <sip:34020000002000000001@192.168.1.100:5060>;tag=inv123
To: <sip:34020000001320000001@192.168.1.200:5060>;tag=dev123
Call-ID: invite-123456@192.168.1.100
CSeq: 2 BYE
Content-Length: 0
```

## 6. 报警信息

### 6.1 报警上报

设备通过 MESSAGE 发送报警：

```xml
<?xml version="1.0" encoding="GB2312"?>
<Notify>
    <CmdType>Alarm</CmdType>
    <SN>12351</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <AlarmPriority>3</AlarmPriority>
    <AlarmMethod>2</AlarmMethod>
    <AlarmTime>2024-01-15T10:35:00</AlarmTime>
    <AlarmDescription>移动侦测报警</AlarmDescription>
    <Longitude>120.123456</Longitude>
    <Latitude>30.654321</Latitude>
</Notify>
```

**处理流程：**
```
设备 → MESSAGE (Alarm) → GB28181Handler
     → MessageHandler.handle()
     → AlarmCommand.handle()
     → dispatchCommand()
     → handleAlarm()
     → postTask('alarm') → 业务系统
```

## 7. 媒体状态通知（GB28181-2022）

### 7.1 截图完成通知

设备完成截图后通过 NOTIFY 通知：

```xml
<?xml version="1.0" encoding="GB2312"?>
<Notify>
    <CmdType>MediaStatus</CmdType>
    <SN>12352</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <NotifyType>SnapshotComplete</NotifyType>
    <SessionID>snapshot_session_123</SessionID>
    <FileURL>http://192.168.1.200/snapshots/image_20240115_103500.jpg</FileURL>
</Notify>
```

**处理流程：**
```
设备 → NOTIFY (MediaStatus) → GB28181Handler
     → MessageHandler.handle()
     → MediaStatusCommand.handle()
     → dispatchCommand()
     → handleMediaStatusReport()
     → postTask('snapshot_complete') → 业务系统
```

### 7.2 媒体流保活通知

设备定期发送媒体流状态：

```xml
<?xml version="1.0" encoding="GB2312"?>
<Notify>
    <CmdType>MediaStatus</CmdType>
    <SN>12353</SN>
    <DeviceID>34020000001320000001</DeviceID>
    <NotifyType>Keepalive</NotifyType>
    <SessionID>stream_session_456</SessionID>
    <SSRC>0123456789</SSRC>
    <BitRate>2048</BitRate>
    <FrameRate>25</FrameRate>
    <Resolution>1920x1080</Resolution>
    <PacketLoss>0.1</PacketLoss>
</Notify>
```

## 8. 语音对讲

### 8.1 语音广播

设备发起广播 INVITE（Subject 包含 "broadcast"）：

```
INVITE sip:34020000002000000001@192.168.1.100:5060 SIP/2.0
Subject: 34020000001320000001:broadcast,34020000002000000001:0
Content-Type: application/sdp
Content-Length: [length]

v=0
o=34020000001320000001 0 0 IN IP4 192.168.1.200
s=Broadcast
c=IN IP4 192.168.1.200
t=0 0
m=audio 8000 RTP/AVP 8
a=rtpmap:8 PCMA/8000
a=recvonly
```

### 8.2 语音对讲

设备发起对讲 INVITE（Subject 包含 "talk"）：

```
INVITE sip:34020000002000000001@192.168.1.100:5060 SIP/2.0
Subject: 34020000001320000001:talk,34020000002000000001:0
Content-Type: application/sdp
Content-Length: [length]

v=0
o=34020000001320000001 0 0 IN IP4 192.168.1.200
s=Talk
c=IN IP4 192.168.1.200
t=0 0
m=audio 8000 RTP/AVP 8
a=rtpmap:8 PCMA/8000
a=sendrecv
```

## 9. 完整业务流程示例

### 9.1 设备上线到点播流程

```php
// 1. 设备注册（自动触发）
// handleRegister() → 设备信息保存到 DeviceManager

// 2. 自动查询设备目录（如果配置启用）
$service->queryCatalog($deviceId);
// → handleCatalog() → postTask('catalog')

// 3. 查询设备信息
$service->queryDeviceInfo($deviceId);
// → handleDeviceInfo() → postTask('device_info')

// 4. 发起视频点播
$streamInfo = $service->startPlay($deviceId, $channelId, $mediaServerId);
// → INVITE → 200 OK → ACK → 流媒体服务器开始接收

// 5. PTZ 云台控制
$service->ptzControl($deviceId, $channelId, 'up', 5);
// → 云台向上移动

sleep(2); // 移动 2 秒

$service->ptzStop($deviceId, $channelId);
// → 云台停止

// 6. 停止点播
$service->stopPlay($deviceId, $channelId, $streamInfo['stream_id']);
// → BYE → 200 OK → 流媒体服务器停止接收
```

### 9.2 移动设备位置跟踪

```php
// 1. 查询当前位置
$service->queryMobilePosition($deviceId);
// → MESSAGE (Query) → MESSAGE (Response)
// → handleMobilePositionReport() → postTask('mobile_position')

// 2. 启动周期上报（每30秒）
$service->queryMobilePosition($deviceId, 30);
// → 设备每30秒自动上报位置

// 3. 接收主动上报（NOTIFY 方式）
// 设备自动发送 NOTIFY (MobilePosition)
// → handleNotify() → MessageHandler → MobilePositionCommand
// → handleMobilePositionReport() → postTask('mobile_position')

// 业务系统接收位置数据：
// {
//     "device_id": "34020000001320000001",
//     "longitude": 120.123456,
//     "latitude": 30.654321,
//     "speed": 35.5,
//     "direction": 90.0,
//     "altitude": 100.0,
//     "time": "2024-01-15T10:30:00",
//     "timestamp": 1705288200
// }
```

## 10. 错误处理

### 10.1 设备不在线

```php
try {
    $service->startPlay($deviceId, $channelId, $mediaServerId);
} catch (\Exception $e) {
    // 设备离线或不可达
    echo "点播失败: " . $e->getMessage();
}
```

### 10.2 命令超时

```php
// 设置超时时间（在 config 中配置）
'timeout' => 10, // 10秒超时

// handleTimeout() 会记录超时事件
```

### 10.3 未知命令类型

```php
// MessageHandler 会抛出 InvalidArgumentException
// handleMessage/handleNotify 捕获并记录日志
// 不会影响其他正常命令的处理
```

## 相关文档

- [MESSAGE_NOTIFY_UNIFICATION.md](MESSAGE_NOTIFY_UNIFICATION.md) - MESSAGE 和 NOTIFY 统一架构
- [CLIENT_USAGE.md](CLIENT_USAGE.md) - GB28181 客户端使用指南
- [GB28181_COMMAND_GUIDE.md](GB28181_COMMAND_GUIDE.md) - 命令协议详解

## 参考标准

- GB/T 28181-2016: 公共安全视频监控联网系统信息传输、交换、控制技术要求
- GB/T 28181-2022: 公共安全视频监控联网系统信息传输、交换、控制技术要求（新版）
