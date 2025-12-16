# GB28181 设备持久化方案

## 问题背景

在开发测试过程中发现，当 `Ctrl+C` 退出 GB28181 Gateway 后重新启动，DeviceManager 中的设备列表丢失，导致心跳机制失效。需要一个可靠的设备持久化方案。

## 方案选择

采用 **方案2(API拉取) + 方案1(文件缓存)混合方案**

### 为什么选择这个方案？

| 对比项 | 纯文件序列化 | 纯API拉取 | **混合方案** |
|--------|-------------|-----------|-------------|
| 可靠性 | ❌ Ctrl+C无法捕获 | ✅ 数据权威 | ✅ 双重保障 |
| 启动速度 | ✅ 快速 | ⚠️ 依赖网络 | ✅ API优先+缓存兜底 |
| 数据一致性 | ❌ 可能过期 | ✅ 最新 | ✅ 最新(API成功时) |
| 容错能力 | ⚠️ 文件损坏风险 | ❌ API故障无法启动 | ✅ API失败用缓存 |
| 集群支持 | ❌ 单机 | ✅ 多实例 | ✅ 多实例 |
| 维护成本 | ⚠️ 需修改C扩展 | ✅ 无额外依赖 | ✅ 纯PHP实现 |

## 架构设计

```
┌─────────────────────────────────────────────────────────────┐
│                    GB28181 Gateway 启动                      │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
              ┌───────────────────────────────┐
              │   DeviceManager 初始化        │
              │   loadDevices()               │
              └───────────────────────────────┘
                              │
                ┌─────────────┴─────────────┐
                │                           │
                ▼                           ▼
    ┌───────────────────┐       ┌──────────────────┐
    │  1. 尝试 API 加载  │       │  2. API 失败？    │
    │  GET /api/v2/gb/   │       │  加载本地缓存     │
    │     device/online  │       │                  │
    └───────────────────┘       └──────────────────┘
                │                           │
                │ 成功                      │ 失败
                ▼                           ▼
    ┌───────────────────┐       ┌──────────────────┐
    │  恢复在线设备列表  │       │  3. 缓存也失败？  │
    │  保存到本地缓存    │       │  空列表启动       │
    └───────────────────┘       └──────────────────┘
                │
                ▼
    ┌───────────────────────────────────┐
    │  运行时：每60秒自动保存缓存         │
    │  (在 checkTimeout() 中触发)        │
    └───────────────────────────────────┘
```

## 实现细节

### 1. API 端实现 (gbvr-iot)

#### 新增接口: `GET /api/v2/gb/device/online`

**文件**: `app/api/v2/controller/GB28181DeviceController.php`

```php
/**
 * 获取在线设备列表（用于信令网关启动时恢复设备状态）
 */
public function online(Request $request)
{
    $devices = $this->getDeviceService()->searchDevices(
        ['status' => 'online'],
        ['last_heartbeat_at' => 'DESC'],
        0,
        1000, // 最多1000个在线设备
        ['device_id', 'ip', 'port', 'from_uri', 'user_agent', 'registered_at', 'last_heartbeat_at', 'expires']
    );
    
    // 转换为Gateway需要的格式
    $result = [];
    foreach ($devices as $device) {
        $result[] = [
            'device_id' => $device['device_id'],
            'uri' => $device['from_uri'],
            'ip' => $device['ip'],
            'port' => $device['port'],
            'user_agent' => $device['user_agent'],
            'registered_at' => strtotime($device['registered_at']),
            'timestamp' => strtotime($device['last_heartbeat_at']),
            'expires' => $device['expires'],
        ];
    }
    
    return $this->createSuccessJsonResponse($result);
}
```

**返回格式**:
```json
{
  "code": 0,
  "data": [
    {
      "device_id": "34020000001320000001",
      "uri": "sip:34020000001320000001@3402000000",
      "ip": "192.168.1.100",
      "port": 5060,
      "user_agent": "HIKVISION DS-7916N-K4",
      "registered_at": 1701234567,
      "timestamp": 1701234890,
      "expires": 3600
    }
  ]
}
```

### 2. Gateway 端实现 (gb28181-gateway)

#### DeviceManager 改造

**文件**: `src/Device/DeviceManager.php`

**新增属性**:
```php
private string $cacheFile = '/tmp/gb28181_devices.cache';
/** @var callable|null */
private $apiLoader = null;
private int $lastCacheSave = 0;
private int $cacheSaveInterval = 60;  // 每60秒保存一次缓存
```

**核心方法**:

##### 1) `loadDevices()` - 启动时加载

```php
private function loadDevices(): void
{
    // 1. 优先从API拉取(最新数据)
    if ($this->apiLoader && $devices = ($this->apiLoader)()) {
        // 重建Device对象
        foreach ($devices as $deviceData) {
            $device = new Device($deviceId, $deviceData);
            $device->markRegistered();
            $device->status = 'online';
            $this->devices[$deviceId] = $device;
        }
        
        // 保存到本地缓存
        $this->saveCache();
        return;
    }
    
    // 2. API失败,从本地缓存恢复
    if (file_exists($this->cacheFile)) {
        $cached = json_decode(file_get_contents($this->cacheFile), true);
        // ... 恢复逻辑
        return;
    }
    
    // 3. 全失败,空启动
    $this->log("⚠️  无法加载设备数据,以空列表启动", 'WARNING');
}
```

##### 2) `saveCache()` - 保存缓存

```php
private function saveCache(): void
{
    $devicesData = [];
    foreach ($this->devices as $device) {
        if ($device->isOnline()) {
            $devicesData[] = [
                'device_id' => $device->deviceId,
                'uri' => $device->uri,
                'ip' => $device->ip,
                'port' => $device->port,
                // ... 其他字段
            ];
        }
    }
    
    file_put_contents($this->cacheFile, json_encode([
        'timestamp' => time(),
        'devices' => $devicesData,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
```

##### 3) `checkTimeout()` - 定期保存

```php
public function checkTimeout(): array
{
    // ... 原有心跳检查逻辑
    
    // 定期保存缓存(每分钟一次)
    if ($now - $this->lastCacheSave >= $this->cacheSaveInterval) {
        $this->saveCache();
        $this->lastCacheSave = $now;
    }
    
    return $timeoutDevices;
}
```

#### GB28181Handler 初始化

**文件**: `src/Handlers/GB28181Handler.php`

```php
$this->deviceManager = new DeviceManager(
    $this->config['heartbeat_timeout'],
    $this->config['check_interval'],
    [
        'cache_file' => $this->config['device_cache_file'] ?? '/tmp/gb28181_devices.cache',
        'api_loader' => function() {
            // 从hock API拉取在线设备列表
            $url = $this->config['api_hock_uri'] . '/online';
            $response = $this->curlGet($url);
            
            if (!$response || $response['code'] !== 0) {
                return null;
            }
            
            return $response['data'] ?? [];
        }
    ]
);
```

**新增方法**: `curlGet()`

```php
private function curlGet(string $url): ?array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Token: ' . $this->config['api_hock_token'],
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    // ... 其他设置
    
    $result = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($result, true);
}
```

### 3. 配置文件

**文件**: `examples/sip_server_config.php`

```php
return [
    // ... 其他配置
    
    // ========== 持久化配置 ==========
    
    /**
     * 设备缓存文件路径
     * 用于重启后恢复设备列表
     */
    'device_cache_file' => '/tmp/gb28181_devices.cache',
    
    'hock' => [
        'url' => 'http://127.0.0.1:8886/api/v2/gb/device',  // 注意：去掉了/server/hock
        'token' => 'your_token_here'
    ]
];
```

## 缓存文件格式

**文件路径**: `/tmp/gb28181_devices.cache`

```json
{
    "timestamp": 1701234567,
    "count": 2,
    "devices": [
        {
            "device_id": "34020000001320000001",
            "uri": "sip:34020000001320000001@3402000000",
            "ip": "192.168.1.100",
            "port": 5060,
            "user_agent": "HIKVISION DS-7916N-K4",
            "registered_at": 1701234567,
            "last_heartbeat": 1701234890,
            "expires": 3600
        }
    ]
}
```

## 工作流程

### 场景1: 正常启动 (API可用)

```
1. Gateway 启动
   ↓
2. DeviceManager 调用 api_loader
   ↓
3. GET http://127.0.0.1:8886/api/v2/gb/device/online
   ↓
4. 返回 5 个在线设备
   ↓
5. 重建 Device 对象
   ↓
6. 保存到 /tmp/gb28181_devices.cache
   ↓
7. 日志: "从API加载 5 个在线设备"
```

### 场景2: API故障 (使用缓存)

```
1. Gateway 启动
   ↓
2. DeviceManager 调用 api_loader
   ↓
3. CURL Error: Connection refused
   ↓
4. 读取 /tmp/gb28181_devices.cache
   ↓
5. 恢复 5 个设备 (缓存时间: 2023-11-29 10:30:00)
   ↓
6. 日志: "⚠️  API不可用,使用本地缓存(可能过期)"
```

### 场景3: 全失败 (空启动)

```
1. Gateway 启动
   ↓
2. API 不可用
   ↓
3. 缓存文件不存在或损坏
   ↓
4. devices = []
   ↓
5. 日志: "⚠️  无法加载设备数据,以空列表启动"
   ↓
6. 等待设备重新注册
```

### 场景4: 运行时缓存更新

```
每 30 秒 tick() 调用:
   ↓
checkTimeout()
   ↓
检查心跳超时
   ↓
每 60 秒保存一次缓存
   ↓
/tmp/gb28181_devices.cache 更新
```

## 优势总结

### ✅ 可靠性
- API是权威数据源,数据最新
- 缓存兜底,API故障不影响启动
- 双重保障,容错能力强

### ✅ 性能
- 启动时一次性批量加载
- 无需修改C扩展
- 运行时定期保存,低开销

### ✅ 可维护性
- 纯PHP实现,易于调试
- 日志清晰,故障排查方便
- 配置灵活,支持自定义路径

### ✅ 扩展性
- 支持集群部署(多实例从同一API拉取)
- 支持水平扩容
- 易于集成监控告警

## 注意事项

1. **API接口权限**: 确保 `api_hock_token` 正确配置
2. **缓存路径权限**: `/tmp/gb28181_devices.cache` 需要写入权限
3. **缓存清理**: 定期检查缓存文件大小,避免设备过多导致文件过大
4. **时钟同步**: API端和Gateway端时钟需同步,避免时间戳错误

## 测试场景

### 测试1: 正常启动恢复

```bash
# 1. 启动Gateway,注册2个设备
php examples/gb28181_server.php

# 2. Ctrl+C 退出

# 3. 重新启动
php examples/gb28181_server.php

# 预期: 日志显示"从API加载 2 个在线设备"
```

### 测试2: API故障恢复

```bash
# 1. 停止gbvr-iot API服务
systemctl stop gbvr-iot

# 2. 启动Gateway
php examples/gb28181_server.php

# 预期: 日志显示"⚠️  API不可用,使用本地缓存"
```

### 测试3: 缓存文件验证

```bash
# 查看缓存内容
cat /tmp/gb28181_devices.cache | jq

# 验证设备数量
jq '.count' /tmp/gb28181_devices.cache
```

## 未来优化

1. **Redis缓存**: 替换文件缓存,支持分布式
2. **增量更新**: 仅更新变化的设备,减少网络开销
3. **健康检查**: 定期验证设备可达性,自动清理失效设备
4. **指标监控**: 暴露Prometheus指标,监控设备状态
