# StreamProxy API 文档

## 数据结构

### StreamProxy 对象

```json
{
  "id": 1,
  "proxy_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "name": "海康摄像头-01",
  "type": "pull",                    // pull(拉流) | push(推流)
  "protocol": "rtsp",                // rtsp | rtmp | http-flv
  "source_url": "rtsp://admin:12345@192.168.1.100:554/Streaming/Channels/101",
  "app": "proxy",
  "stream": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
  "vhost": "__defaultVhost__",
  "media_server_id": "1",
  "status": "online",                // online | offline | stopped | error
  "last_heartbeat_at": "2026-03-06 15:30:00",
  "error_message": null,
  "record_plan_id": 0,
  "record_status": 0,                // 0=未录像, 1=录像中
  "enable_auto_reconnect": 1,
  "max_retry_count": 10,
  "current_retry_count": 0,
  "timeout_sec": 10,
  "rtp_type": 0,                     // 0=TCP, 1=UDP
  "enable_hls": 1,
  "enable_mp4": 0,
  "viewer_count": 5,
  "total_start_count": 3,
  "total_reconnect_count": 1,
  "description": "门口监控",
  "tags": ["重点区域", "24小时"],
  "zlm_key": "stream_proxy_a1b2c3d4",
  "started_at": "2026-03-06 14:00:00",
  "stopped_at": null,
  "created_at": "2026-03-06 13:00:00",
  "updated_at": "2026-03-06 15:30:00"
}
```

---

## API接口

### 1. 获取列表

`GET /stream-proxies`

**请求参数**:
```
status          状态筛选: online|offline|stopped|error
type            类型筛选: pull|push
protocol        协议筛选: rtsp|rtmp|http-flv
media_server_id 流媒体服务器ID
record_plan_id  录像计划ID
keyword         搜索关键词
sort            排序: created_at|started_at|last_heartbeat_at
start           分页起始 (默认0)
limit           每页数量 (默认20)
```

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "list": [/* StreamProxy对象数组 */],
    "paginator": {
      "total": 50,
      "start": 0,
      "limit": 20,
      "current_page": 1,
      "total_pages": 3
    }
  }
}
```

---

### 2. 创建流代理

`POST /stream-proxies`

#### 拉流场景（pull）

用于接入海康/大华等摄像头的RTSP流。

**请求Body**:
```json
{
  "name": "海康摄像头-01",
  "type": "pull",
  "protocol": "rtsp",
  "source_url": "rtsp://admin:12345@192.168.1.100:554/Streaming/Channels/101",
  "media_server_id": "1",
  "description": "门口监控",
  "tags": ["重点区域"],
  "enable_auto_reconnect": 1,
  "max_retry_count": 10,
  "timeout_sec": 10,
  "rtp_type": 0,
  "enable_hls": 1,
  "enable_mp4": 0
}
```

#### 推流场景（push）

用于接收OBS/FFmpeg等工具的推流。

**请求Body**:
```json
{
  "name": "OBS直播间-01",
  "type": "push",
  "protocol": "rtmp",
  "media_server_id": "1",
  "stream": "obs_live_01",
  "description": "主播直播间",
  "tags": ["直播"],
  "enable_hls": 1
}
```

**推流场景说明**:
- `stream` 字段可选，支持自定义推流ID（仅支持字母、数字、下划线、横线）
- 如果不提供 `stream`，系统会自动生成UUID作为推流ID
- **建议自定义stream ID**，方便在OBS中配置（如：`obs_live_01`）
- 创建成功后，调用 `GET /stream-proxies/{id}/push-url` 获取推流地址

**必填字段**:
- `name` - 名称
- `type` - 类型 (pull/push)
- `protocol` - 协议 (rtsp/rtmp/http-flv)
- `source_url` - 源地址 (仅type=pull时必填)
- `media_server_id` - 流媒体服务器ID

**可选字段**:
- `stream` - 自定义流ID（推流场景建议填写）

**响应**:
```json
{
  "code": 0,
  "msg": "流代理创建成功",
  "data": {/* StreamProxy对象 */}
}
```

---

### 3. 获取详情

`GET /stream-proxies/{id}`

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {/* StreamProxy对象 */}
}
```

---

### 4. 更新流代理

`PUT /stream-proxies/{id}`

**请求Body**:
```json
{
  "name": "海康摄像头-01（已更新）",
  "description": "门口监控摄像头",
  "enable_auto_reconnect": 1,
  "max_retry_count": 15,
  "tags": ["重点区域"]
}
```

**可更新字段**:
- `name`, `description`, `tags`
- `enable_auto_reconnect`, `max_retry_count`, `timeout_sec`
- `rtp_type`, `enable_hls`, `enable_mp4`

**注意**: 流代理在线时，无法修改 `type`, `protocol`, `source_url`, `app`, `stream`, `media_server_id`

**响应**:
```json
{
  "code": 0,
  "msg": "更新成功",
  "data": {/* StreamProxy对象 */}
}
```

---

### 5. 删除流代理

`DELETE /stream-proxies/{id}`

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "message": "流代理已删除"
  }
}
```

---

### 6. 启动流代理

`POST /stream-proxies/{id}/start`

**功能**: 调用ZLM API拉取源流，更新状态为online

**响应**:
```json
{
  "code": 0,
  "msg": "流代理已启动",
  "data": {/* StreamProxy对象 */}
}
```

**错误码**:
- `4093008` - 流代理已启动
- `5003012` - 启动失败

---

### 7. 停止流代理

`POST /stream-proxies/{id}/stop`

**功能**: 调用ZLM API删除流代理，更新状态为stopped

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "message": "流代理已停止"
  }
}
```

---

### 8. 重启流代理

`POST /stream-proxies/{id}/restart`

**功能**: 先停止再启动

**响应**:
```json
{
  "code": 0,
  "msg": "流代理已重启",
  "data": {/* StreamProxy对象 */}
}
```

---

### 9. 获取播放地址

`GET /stream-proxies/{id}/play-urls`

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "rtsp": "rtsp://192.168.1.10:554/proxy/b2c3d4e5-f6a7-8901-bcde-f12345678901",
    "rtmp": "rtmp://192.168.1.10:1935/proxy/b2c3d4e5-f6a7-8901-bcde-f12345678901",
    "http_flv": "http://192.168.1.10:8080/proxy/b2c3d4e5-f6a7-8901-bcde-f12345678901.live.flv",
    "ws_flv": "ws://192.168.1.10:8080/proxy/b2c3d4e5-f6a7-8901-bcde-f12345678901.live.flv",
    "hls": "http://192.168.1.10:8080/proxy/b2c3d4e5-f6a7-8901-bcde-f12345678901/hls.m3u8",
    "https_flv": "https://192.168.1.10:4443/proxy/b2c3d4e5-f6a7-8901-bcde-f12345678901.live.flv",
    "wss_flv": "wss://192.168.1.10:4443/proxy/b2c3d4e5-f6a7-8901-bcde-f12345678901.live.flv"
  }
}
```

**前端播放示例**:
 使用国标集成的播放器
---

### 10. 获取推流地址

`GET /stream-proxies/{id}/push-url`

获取推流代理的推流地址，用于配置OBS/FFmpeg等推流工具。

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "rtmp": "rtmp://192.168.1.10:1935/push/obs_live_01",
    "rtsp": "rtsp://192.168.1.10:554/push/obs_live_01",
    "stream_id": "obs_live_01",
    "app": "push",
    "tips": {
      "obs_rtmp": "在OBS中设置推流地址时，服务器填写: rtmp://192.168.1.10:1935/push，串流密钥填写: obs_live_01",
      "ffmpeg": "使用FFmpeg推流: ffmpeg -re -i input.mp4 -c copy -f flv rtmp://192.168.1.10:1935/push/obs_live_01"
    }
  }
}
```

**OBS配置步骤**:

1. 创建推流代理时，自定义 `stream` 字段（如：`obs_live_01`）
2. 调用此接口获取推流地址
3. 在OBS中设置：
   - **服务器**: `rtmp://192.168.1.10:1935/push`
   - **串流密钥**: `obs_live_01`（即stream字段的值）
4. 开始推流后，流代理状态自动变为 `online`
5. 可通过 `GET /stream-proxies/{id}/play-urls` 获取播放地址进行观看

**FFmpeg推流示例**:
```bash
# 推送本地视频文件
ffmpeg -re -i video.mp4 -c copy -f flv rtmp://192.168.1.10:1935/push/obs_live_01

# 推送摄像头画面（Windows）
ffmpeg -f dshow -i video="摄像头名称" -c:v libx264 -f flv rtmp://192.168.1.10:1935/push/obs_live_01

# 推送摄像头画面（Linux）
ffmpeg -f v4l2 -i /dev/video0 -c:v libx264 -f flv rtmp://192.168.1.10:1935/push/obs_live_01
```

---

### 11. 绑定录像计划

`POST /stream-proxies/{id}/bind-plan`

**请求Body**:
```json
{
  "record_plan_id": 1
}
```

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "message": "录像计划已绑定"
  }
}
```

---

### 12. 解绑录像计划

`POST /stream-proxies/{id}/unbind-plan`

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "message": "录像计划已解绑"
  }
}
```

---

### 13. 统计摘要

`GET /stream-proxies/summary`

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "total": 50,
    "by_status": {
      "online": 35,
      "offline": 5,
      "stopped": 8,
      "error": 2
    },
    "by_type": {
      "pull": 45,
      "push": 5
    },
    "recording": {
      "with_plan": 20,
      "recording": 15
    }
  }
}
```

---

### 14. 手动健康检查

`POST /stream-proxies/health-check`

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "total": 35,
    "online": 33,
    "offline": 2
  }
}
```

---

### 15. 获取流代理日志

`GET /stream-proxies/{id}/logs`

获取指定流代理的操作日志。

**请求参数**:
```
start    分页起始 (默认0)
limit    每页数量 (默认20, 最大100)
```

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "list": [
      {
        "id": 1,
        "proxy_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
        "event_type": "started",
        "level": "info",
        "message": "流代理 [海康摄像头-01] 已启动",
        "details": {
          "zlm_key": "stream_proxy_a1b2c3d4",
          "source_url": "rtsp://admin:12345@192.168.1.100:554/Streaming/Channels/101"
        },
        "user_id": null,
        "ip_address": null,
        "created_at": "2026-03-06 14:00:00"
      }
    ],
    "paginator": {
      "total": 150,
      "start": 0,
      "limit": 20,
      "current_page": 1,
      "total_pages": 8
    }
  }
}
```

**日志事件类型**:
- `created` - 创建
- `started` - 启动
- `stopped` - 停止
- `online` - 在线（健康检查通过）
- `offline` - 离线（健康检查失败）
- `error` - 错误
- `reconnect_attempt` - 尝试重连
- `reconnect_success` - 重连成功
- `reconnect_failed` - 重连失败
- `deleted` - 删除

**日志级别**:
- `debug` - 调试
- `info` - 信息
- `warning` - 警告
- `error` - 错误

---

### 16. 获取所有日志（支持筛选）

`GET /stream-proxy-logs`

获取所有流代理的日志，支持多条件筛选。

**请求参数**:
```
proxy_id     流代理ID
event_type   事件类型: created|started|stopped|online|offline|error|reconnect_attempt|reconnect_success|reconnect_failed|deleted
level        日志级别: debug|info|warning|error
start_date   开始日期 (格式: Y-m-d)
end_date     结束日期 (格式: Y-m-d)
keyword      消息关键词搜索
start        分页起始 (默认0)
limit        每页数量 (默认20, 最大100)
```

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "list": [/* 日志对象数组 */],
    "paginator": {
      "total": 500,
      "start": 0,
      "limit": 20,
      "current_page": 1,
      "total_pages": 25
    }
  }
}
```

---

### 17. 清理旧日志

`POST /stream-proxy-logs/cleanup`

清理指定天数之前的日志记录。

**请求Body**:
```json
{
  "days_to_keep": 30
}
```

**参数说明**:
- `days_to_keep`: 保留最近N天的日志（最小7天）

**响应**:
```json
{
  "code": 0,
  "msg": "已清理 1523 条日志",
  "data": {
    "deleted_count": 1523,
    "days_to_keep": 30
  }
}
```

---

## 错误码

### 通用错误
| 错误码 | 说明 |
|--------|------|
| 0 | 成功 |
| 5000305 | 参数缺失 |
| 5000306 | 参数错误 |

### 流代理专用错误
| 错误码 | 说明 |
|--------|------|
| 4043001 | 流代理不存在 |
| 4043002 | 流媒体服务器不存在 |
| 4003003 | 无效的代理类型 |
| 4003004 | 不支持的协议类型 |
| 4003005 | 无效的源地址 |
| 4003006 | 拉流代理必须提供源地址 |
| 4093008 | 流代理已启动 |
| 4093009 | 流代理已停止 |
| 4093011 | 流ID已存在 |
| 5003012 | 启动流代理失败 |
| 5003013 | 停止流代理失败 |

---

## 使用场景
>>> 代码没有参考性，只理解业务

### 场景1：海康摄像头接入

```javascript
// 1. 创建
const res1 = await fetch('/api/admin/stream-proxies', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer YOUR_TOKEN'
  },
  body: JSON.stringify({
    name: '门口监控',
    type: 'pull',
    protocol: 'rtsp',
    source_url: 'rtsp://admin:Admin123@192.168.1.100:554/Streaming/Channels/101',
    media_server_id: '1'
  })
});
const proxy = await res1.json();

// 2. 启动
await fetch(`/api/admin/stream-proxies/${proxy.data.id}/start`, {
  method: 'POST',
  headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
});

// 3. 获取播放地址
const res2 = await fetch(`/api/admin/stream-proxies/${proxy.data.id}/play-urls`, {
  headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
});
const urls = await res2.json();

// 4. 播放
const flvPlayer = flvjs.createPlayer({ type: 'flv', url: urls.data.http_flv });
flvPlayer.attachMediaElement(videoElement);
flvPlayer.load();
flvPlayer.play();
```

### 场景2：大华摄像头接入

```javascript
// 大华RTSP地址格式不同
await fetch('/api/admin/stream-proxies', {
  method: 'POST',
  body: JSON.stringify({
    name: '停车场监控',
    type: 'pull',
    protocol: 'rtsp',
    source_url: 'rtsp://admin:Admin123@192.168.1.101:554/cam/realmonitor?channel=1&subtype=0',
    media_server_id: '1'
  })
});
```

### 场景3：OBS推流

```javascript
// 1. 创建推流代理（自定义stream ID）
const res = await fetch('/api/admin/stream-proxies', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer YOUR_TOKEN'
  },
  body: JSON.stringify({
    name: 'OBS直播间-01',
    type: 'push',
    protocol: 'rtmp',
    stream: 'obs_live_01',  // 自定义推流ID，方便记忆
    media_server_id: '1',
    description: '主播直播间'
  })
});
const proxy = await res.json();

// 2. 获取推流地址
const res2 = await fetch(`/api/admin/stream-proxies/${proxy.data.id}/push-url`, {
  headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
});
const pushInfo = await res2.json();

console.log('推流配置说明:', pushInfo.data.tips.obs_rtmp);
// 输出：在OBS中设置推流地址时，服务器填写: rtmp://192.168.1.10:1935/push，串流密钥填写: obs_live_01

// 3. 在OBS中配置
// 设置 -> 串流 -> 服务: 自定义
// 服务器: rtmp://192.168.1.10:1935/push
// 串流密钥: obs_live_01

// 4. OBS开始推流后，查询流代理状态（自动变为online）
const res3 = await fetch(`/api/admin/stream-proxies/${proxy.data.id}`, {
  headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
});
const status = await res3.json();
console.log('流状态:', status.data.status); // online

// 5. 获取播放地址供观众观看
const res4 = await fetch(`/api/admin/stream-proxies/${proxy.data.id}/play-urls`, {
  headers: { 'Authorization': 'Bearer YOUR_TOKEN' }
});
const playUrls = await res4.json();
console.log('HLS播放地址:', playUrls.data.hls);
console.log('FLV播放地址:', playUrls.data.http_flv);
```

**OBS推流要点**:
- 推荐自定义 `stream` 字段，使用易记的名称（如：obs_live_01）
- 如果不自定义，系统会生成UUID，需要从响应中获取
- 推流成功后，流代理状态会自动变为 `online`
- 健康检查进程会每30秒检查流是否在线
- 可以绑定录像计划，实现自动录制

---

## 常用RTSP地址格式

### 海康威视
```
主码流: rtsp://admin:密码@IP:554/Streaming/Channels/101
子码流: rtsp://admin:密码@IP:554/Streaming/Channels/102
```

### 大华
```
主码流: rtsp://admin:密码@IP:554/cam/realmonitor?channel=1&subtype=0
子码流: rtsp://admin:密码@IP:554/cam/realmonitor?channel=1&subtype=1
```

### 宇视
```
rtsp://admin:密码@IP:554/video1
```

---

## 注意事项

1. **认证**: 所有接口需要携带管理员Token
2. **端口访问**: 确保ZLM的端口可被前端访问
3. **播放器**: 推荐使用flv.js播放HTTP-FLV（延迟低）
4. **自动重连**: 启用后离线流会每60秒尝试重连
5. **健康检查**: 后台每30秒自动检查，也可手动触发
6. **录像**: 绑定录像计划后会根据时间段自动录像

---

**版本**: v1.0.0
**更新日期**: 2026-03-06
