# GB28181 预置位和 2022 新功能 - 使用指南

## 🎯 功能概述

本次更新添加了以下功能:

### 1. 预置位操作
- ✅ 设置预置位 (Preset Set)
- ✅ 调用预置位 (Preset Call)  
- ✅ 删除预置位 (Preset Delete)

### 2. GB/T 28181-2022 新功能
- ✅ 设备升级 (Device Upgrade)
- ✅ 图像抓拍 (Snapshot)
- ✅ MediaStatus 通知处理

## 📦 已修改的文件

### 客户端 SDK
- ✅ `CoreW/Sdk/PSipGateway/Gb28181Client.php` - 添加 `presetControl()`, `deviceUpgrade()`, `snapshot()` 方法

### 业务层
- ✅ `CoreW/Business/GB/Gb28181Service.php` - 添加 `presetSet()`, `presetCall()`, `presetDelete()`, `deviceUpgrade()`, `snapshot()` 方法

### 网关项目
- ✅ `gb28181-gateway/src/Message/CommandDispatcher.php` - 添加预置位和2022功能的命令处理
- ✅ `gb28181-gateway/src/Handlers/GB28181Handler.php` - 添加 `handleMediaStatus()` 处理 MediaStatus 通知

### 测试工具
- ✅ `gbvr-iot/app/command/GB28181Test.php` - 添加预置位和2022功能的测试菜单

## 🚀 快速使用

### 1. 预置位操作

```php
// 初始化服务
$gb28181 = new Gb28181Service($bfw);

// 设置预置位5
$gb28181->presetSet('34020000001320948622', '34020000001320000001', 5);

// 调用预置位5 (云台移动到预置位)
$gb28181->presetCall('34020000001320948622', '34020000001320000001', 5);

// 删除预置位5
$gb28181->presetDelete('34020000001320948622', '34020000001320000001', 5);
```

**命令格式:**
```
设置预置位5: A50F018100050088
调用预置位5: A50F018200050089
删除预置位5: A50F01830005008A

格式: A5 0F 01 [指令码] 00 [预置位ID] 00 [校验码]
- 0x81: 设置预置位
- 0x82: 调用预置位
- 0x83: 删除预置位
```

### 2. 设备升级 (GB28181-2022)

```php
// 发送设备升级命令
$result = $gb28181->deviceUpgrade(
    '34020000001320948622',  // 设备ID
    'Hikvision',              // 制造商
    'V5.7.12_build230801'     // 固件版本
);

// 返回值
[
    'success' => true,
    'session_id' => 'A1FBCA20ACF640D8BA317E4DC4620802677281FD',
    'sn' => 80267728
]
```

**XML 示例:**
```xml
<?xml version="1.0" encoding="GB2312"?>
<Control>
  <CmdType>DeviceControl</CmdType>
  <SN>80267728</SN>
  <DeviceID>34020000001320948622</DeviceID>
  <DeviceUpgrade>
    <Manufacturer>Hikvision</Manufacturer>
    <Firmware>V5.7.12_build230801</Firmware>
  </DeviceUpgrade>
</Control>
```

**注意事项:**
- ⚠️ 设备收到升级命令后会先注销
- ⚠️ 升级完成后设备会重新注册
- ⚠️ 升级过程中设备离线,视频流会中断

### 3. 图像抓拍 (GB28181-2022)

```php
// 发送抓拍命令
$result = $gb28181->snapshot(
    '34020000001320948622',  // 设备ID
    '34020000001320000001',  // 通道ID
    'JPEG'                    // 图片格式: JPEG/PNG/BMP
);

// 返回值
[
    'success' => true,
    'session_id' => 'B2FBCA20ACF640D8BA317E4DC4620802677281FE',
    'sn' => 80267729,
    'image_format' => 'JPEG'
]
```

**XML 示例:**
```xml
<?xml version="1.0" encoding="GB2312"?>
<Control>
  <CmdType>DeviceControl</CmdType>
  <SN>80267729</SN>
  <DeviceID>34020000001320000001</DeviceID>
  <Snapshot>
    <SessionID>B2FBCA20ACF640D8BA317E4DC4620802677281FE</SessionID>
    <ImageFormat>JPEG</ImageFormat>
  </Snapshot>
</Control>
```

**设备响应 (MediaStatus 通知):**
```xml
<?xml version="1.0" encoding="GB2312"?>
<Notify>
  <CmdType>MediaStatus</CmdType>
  <SN>80267730</SN>
  <DeviceID>34020000001320000001</DeviceID>
  <NotifyType>SnapshotComplete</NotifyType>
  <SessionID>B2FBCA20ACF640D8BA317E4DC4620802677281FE</SessionID>
  <FileURL>http://192.168.1.73/snapshot/image.jpg</FileURL>
</Notify>
```

### 4. 处理 MediaStatus 通知

抓拍完成后,设备会通过 NOTIFY 消息发送 `MediaStatus` 通知,网关会自动处理并推送到业务系统。

**Hook 回调处理:**
```php
// 在 Hook API 中接收抓拍完成通知
Route::post('/gb28181/hook', function (Request $request) {
    $data = $request->all();
    
    if ($data['task_type'] === 'snapshot_complete') {
        $deviceId = $data['device_id'];
        $sessionId = $data['session_id'];
        $fileUrl = $data['file_url'];
        
        // 下载图片
        $imageContent = file_get_contents($fileUrl);
        
        // 保存到本地或OSS
        Storage::put("snapshots/{$deviceId}_{$sessionId}.jpg", $imageContent);
        
        // 更新数据库
        DB::table('device_snapshots')->insert([
            'device_id' => $deviceId,
            'session_id' => $sessionId,
            'file_url' => $fileUrl,
            'created_at' => now()
        ]);
    }
});
```

## 🧪 测试方法

### 使用交互式测试工具

```bash
cd /path/to/gbvr-iot
php think gb:test
```

**菜单选项:**
```
═══════════════════════════════════════════════════════════
         GB28181 Interactive Testing Tool
═══════════════════════════════════════════════════════════

请选择操作：

1. 查询设备目录 (Catalog)
2. 查询设备信息 (DeviceInfo)
3. 查询设备状态 (DeviceStatus)
4. 查询录像文件 (RecordInfo)
5. 开始实时视频 (Live)
6. 停止实时视频
7. 开始录像回放 (Playback)
8. 停止录像回放
9. PTZ 云台控制
10. 查看会话信息
11. 预置位管理         ⭐ 新增
12. 设备升级 (2022)    ⭐ 新增
13. 图像抓拍 (2022)    ⭐ 新增
0. 退出
```

### 测试流程

#### 1. 测试预置位
```
选择: 11. 预置位管理
→ 选择操作: 设置预置位
→ 输入设备ID: 34020000001320948622
→ 输入通道ID: 34020000001320000001
→ 输入预置位编号: 5

✓ 预置位命令已发送: 设置预置位, 编号: 5
```

#### 2. 测试设备升级
```
选择: 12. 设备升级 (2022)
→ 输入设备ID: 34020000001320948622
→ 输入制造商: Hikvision
→ 输入固件版本: V5.7.12_build230801

✓ 设备升级命令已发送
  制造商: Hikvision
  固件版本: V5.7.12_build230801
⚠ 注意：设备升级前会注销，升级完成后会重新注册
```

#### 3. 测试图像抓拍
```
选择: 13. 图像抓拍 (2022)
→ 输入设备ID: 34020000001320948622
→ 输入通道ID: 34020000001320000001
→ 选择图片格式: JPEG

✓ 图像抓拍命令已发送
  通道ID: 34020000001320000001
  图片格式: JPEG
  Session ID: B2FBCA20ACF640D8BA317E4DC4620802677281FE
⚠ 设备抓拍完成后会发送 MediaStatus 通知，包含图片URL
```

## 📊 命令流程图

### 预置位操作流程
```
PHP应用
  ↓ presetSet()
Gb28181Service
  ↓ presetControl('set', 5)
Gb28181Client
  ↓ Redis队列: preset_set
GB28181Handler
  ↓ CommandDispatcher
  ↓ buildPresetCommand()
  ↓ A50F018100050088
设备
  → 200 OK
```

### 图像抓拍流程
```
PHP应用
  ↓ snapshot()
Gb28181Service
  ↓ snapshot()
Gb28181Client
  ↓ Redis队列: snapshot
GB28181Handler
  ↓ CommandDispatcher
  ↓ XML: <Snapshot>
设备
  → 200 OK
  → 抓拍处理...
  → NOTIFY: MediaStatus/SnapshotComplete
  → FileURL: http://...
GB28181Handler
  ↓ handleMediaStatus()
  ↓ postTask('snapshot_complete')
Hook API
  ↓ 下载图片
  ↓ 保存到存储
```

## ⚙️ 配置说明

### Redis 队列配置
```php
// config/gb28181.php
return [
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'queue' => 'gb28181:commands',  // 命令队列
    ],
];
```

### Hook API 配置
```php
// gb28181-gateway 配置
'api_push_url' => 'http://your-api.com/gb28181/hook',  // 推送地址
'api_pull_url' => 'http://your-api.com/gb28181/devices',  // 拉取设备
```

## 🔍 调试方法

### 1. 查看网关日志
```bash
tail -f /path/to/gb28181-gateway/logs/gateway.log
```

### 2. 查看 Redis 队列
```bash
redis-cli
> LLEN gb28181:commands
> LRANGE gb28181:commands 0 -1
```

### 3. 抓包查看 SIP 信令
```bash
tcpdump -i any -s 0 -A 'port 5060' -w gb28181.pcap
```

## ⚠️ 注意事项

### 预置位
- 预置位编号范围: 1-255
- 需要设备支持预置位功能
- 调用不存在的预置位会失败

### 设备升级
- 设备会在升级前注销
- 升级过程中所有流会中断
- 升级失败设备可能需要手动恢复
- 建议在业务低峰期进行

### 图像抓拍
- SessionID 必须保持一致性
- FileURL 可能是 HTTP 或 FTP 地址
- 图片有效期可能有限,需及时下载
- 大分辨率图片下载可能较慢

### 兼容性
- 预置位: GB28181-2011/2016/2022 均支持
- 设备升级: 仅 GB28181-2022
- 图像抓拍: 仅 GB28181-2022
- MediaStatus: 仅 GB28181-2022

## 📚 参考文档

- [GB/T 28181-2016](docs/扩展2022版本国标协议方案.md)
- [GB/T 28181-2022](docs/扩展2022版本国标协议方案.md)
- [PTZ 命令格式](docs/GB28181_COMMAND_GUIDE.md)
- [API 文档](examples/README_GB28181.md)

## 🆘 常见问题

### Q1: 预置位命令发送成功但设备无响应?
**A:** 检查:
1. 设备是否支持预置位功能
2. 预置位编号是否在设备支持范围内
3. 命令格式是否正确 (查看网关日志)

### Q2: 抓拍命令发送后没有收到 MediaStatus 通知?
**A:** 检查:
1. 设备是否支持 GB28181-2022
2. Hook API 地址是否正确
3. 网关日志中是否有 NOTIFY 消息
4. 设备是否正确处理了抓拍命令

### Q3: 设备升级后无法重新注册?
**A:** 可能原因:
1. 升级失败,设备处于异常状态
2. 固件版本不匹配
3. 网络配置被重置
建议: 手动重启设备或恢复出厂设置

## 🎉 完成!

现在你可以使用预置位和 GB28181-2022 新功能了!

如有问题,请查看日志或联系技术支持。
