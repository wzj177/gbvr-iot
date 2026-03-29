# StreamProxy 模块说明

## 模块概述

**功能**：支持海康/大华等非国标摄像头RTSP流接入、OBS等第三方RTMP推流管理

**技术栈**：
- **流媒体服务器**：ZLMediaKit (通过HTTP API调用)
- **数据库**：MySQL
- **缓存**：Redis (DAO层)
- **后台进程**：Workerman Timer

**特点**：
- ✅ 完全独立，未修改任何GB28181代码
- ✅ 支持健康检查、自动重连
- ✅ 支持绑定录像计划
- ✅ 输出多种协议（RTSP/RTMP/HLS/FLV）

---

## 模块结构

```
StreamProxy/
├── Dao/                          # 数据访问层
│   ├── StreamProxyDao.php        # DAO接口
│   └── Impl/
│       └── StreamProxyDaoImpl.php # DAO实现
├── Service/                      # 业务逻辑层
│   ├── StreamProxyService.php    # Service接口
│   └── Impl/
│       └── StreamProxyServiceImpl.php # Service实现
└── Exception/                    # 异常类
    └── StreamProxyException.php  # 流代理异常
```

---

## 文件清单

### 核心业务层

| 文件 | 说明 | 行数 |
|------|------|------|
| `CoreW/Business/StreamProxy/Dao/StreamProxyDao.php` | DAO接口 | ~20 |
| `CoreW/Business/StreamProxy/Dao/Impl/StreamProxyDaoImpl.php` | DAO实现 | ~110 |
| `CoreW/Business/StreamProxy/Dao/StreamProxyLogDao.php` | 日志DAO接口 | ~40 |
| `CoreW/Business/StreamProxy/Dao/Impl/StreamProxyLogDaoImpl.php` | 日志DAO实现 | ~60 |
| `CoreW/Business/StreamProxy/Service/StreamProxyService.php` | Service接口 | ~70 |
| `CoreW/Business/StreamProxy/Service/Impl/StreamProxyServiceImpl.php` | Service实现 | ~650 |
| `CoreW/Business/StreamProxy/Exception/StreamProxyException.php` | 异常定义 | ~80 |

### 控制器层

| 文件 | 说明 | 行数 |
|------|------|------|
| `app/admin/controller/StreamProxyController.php` | REST API控制器 | ~420 |

### 后台进程

| 文件 | 说明 | 行数 |
|------|------|------|
| `app/process/StreamProxyHealthCheckProcess.php` | 健康检查进程(30s) | ~70 |
| `app/process/StreamProxyAutoReconnectProcess.php` | 自动重连进程(60s) | ~70 |

### 数据库

| 文件 | 说明 |
|------|------|
| `migrations/20260306000001_create_stream_proxies_table.php` | 流代理表迁移 |
| `migrations/20260306000002_create_stream_proxy_logs_table.php` | 流代理日志表迁移 |

### 扩展的文件

| 文件 | 修改内容 |
|------|---------|
| `CoreW/Sdk/ZLMediaKit/ZLMClient.php` | 新增 addStreamProxy/delStreamProxy/getProxyList |
| `CoreW/Business/MediaServer/Strategy/MediaServerStrategyInterface.php` | 新增流代理接口 |
| `CoreW/Business/MediaServer/Strategy/ZLMediaKitStrategy.php` | 实现流代理接口 |
| `CoreW/Business/MediaServer/Strategy/SRSStrategy.php` | 新增SRS策略实现（查询功能） |
| `CoreW/Business/MediaServer/Strategy/MediaServerStrategyFactory.php` | 新增create()别名方法 |
| `app/admin/config/routes/index.php` | 注册 /stream-proxies 路由组 |
| `app/process/AutoRecordProcess.php` | 扩展支持流代理录像 |
| `config/process.php` | 注册健康检查和自动重连进程 |
| `config/log.php` | 新增 stream_proxy 日志channel |

---

## 数据库表

### gv_stream_proxies

**主要字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| proxy_id | varchar(64) | 流代理UUID |
| name | varchar(100) | 名称 |
| type | enum | pull/push |
| protocol | varchar(20) | rtsp/rtmp/http-flv |
| source_url | varchar(500) | 源地址 |
| app | varchar(64) | ZLM应用名 |
| stream | varchar(100) | ZLM流ID |
| media_server_id | varchar(64) | 流媒体服务器ID |
| status | enum | online/offline/stopped/error |
| record_plan_id | int | 录像计划ID |
| record_status | tinyint | 录像状态 |

**索引**：
- `uk_proxy_id` (UNIQUE)
- `uk_app_stream` (UNIQUE)
- `idx_status`, `idx_media_server`, `idx_record_plan`

### gv_stream_proxy_logs

**主要字段**：

| 字段 | 类型 | 说明 |
|------|------|------|
| id | bigint | 主键 |
| proxy_id | varchar(64) | 流代理ID |
| event_type | varchar(20) | 事件类型（created/started/stopped/online/offline/error/reconnect_*） |
| level | varchar(10) | 日志级别（debug/info/warning/error） |
| message | varchar(500) | 日志消息 |
| details | json | 详细信息 |
| user_id | int | 操作用户ID |
| ip_address | varchar(45) | 操作IP地址 |
| created_at | datetime | 创建时间 |

**索引**：
- `idx_proxy_id`, `idx_event_type`, `idx_level`, `idx_created_at`

---

## API接口

**Base URL**: `/api/admin/stream-proxies`

### 流代理管理
- `GET /` - 列表
- `POST /` - 创建
- `GET /{id}` - 详情
- `PUT /{id}` - 更新
- `DELETE /{id}` - 删除

### 流控制
- `POST /{id}/start` - 启动
- `POST /{id}/stop` - 停止
- `POST /{id}/restart` - 重启
- `GET /{id}/play-urls` - 播放地址

### 录像管理
- `POST /{id}/bind-plan` - 绑定录像计划
- `POST /{id}/unbind-plan` - 解绑录像计划

### 统计监控
- `GET /summary` - 统计摘要
- `POST /health-check` - 手动健康检查

### 日志管理
- `GET /{id}/logs` - 获取指定流代理的日志
- `GET /api/admin/stream-proxy-logs` - 获取所有日志（支持筛选）
- `POST /api/admin/stream-proxy-logs/cleanup` - 清理旧日志

详细API文档见：`docs/StreamProxy-API.md`

---

## 后台任务

### StreamProxyHealthCheckProcess

**执行间隔**: 30秒

**功能**:
1. 查询所有 `status='online'` 的流代理
2. 调用 ZLM `getMediaList` 检查流是否在线
3. 在线 → 更新 `last_heartbeat_at`
4. 离线 → 标记 `status='offline'`

**配置**: `.env` 中设置 `ENABLE_STREAM_PROXY_HEALTH_CHECK=1`

### StreamProxyAutoReconnectProcess

**执行间隔**: 60秒

**功能**:
1. 查询 `status IN ('offline','error')` 且 `enable_auto_reconnect=1`
2. 检查 `current_retry_count < max_retry_count`
3. 符合条件 → 调用 `startProxy()` 重新拉流
4. 更新重试计数

**配置**: `.env` 中设置 `ENABLE_STREAM_PROXY_AUTO_RECONNECT=1`

### AutoRecordProcess 扩展

**功能**:
1. 查询 `status='online'` 且 `record_plan_id > 0` 的流代理
2. 检查当前时间是否在录像计划时间段内
3. 在时间段 → 启动录像
4. 不在时间段 → 停止录像

---

## 依赖说明

### 内部依赖

- **ZLMClient** (`CoreW/Sdk/ZLMediaKit/ZLMClient.php`)
  - 调用 ZLM 的 `addStreamProxy`, `delStreamProxy`, `getMediaList` API

- **MediaServerService** (`CoreW/Business/MediaServer`)
  - 获取流媒体服务器配置

- **RecordPlanService** (`CoreW/Business/Record`)
  - 绑定录像计划时验证计划是否存在

### 外部依赖

#### ZLMediaKit (推荐)
- HTTP API端口：默认 8080
- RTSP端口：默认 554
- RTMP端口：默认 1935
- **支持功能**：
  - ✅ 动态添加/删除流代理（HTTP API）
  - ✅ 健康检查
  - ✅ 多协议输出

#### SRS (部分支持)
- HTTP API端口：默认 1985
- RTSP端口：默认 554 (不支持)
- RTMP端口：默认 1935
- **支持功能**：
  - ✅ 查询流状态
  - ✅ 健康检查
  - ❌ 动态添加/删除流代理（需要配置文件+reload）
- **限制说明**：
  - SRS的流代理需要在配置文件中配置 `ingest` 块
  - 添加/删除流代理需要修改配置文件并调用 `/api/v1/raw?rpc=reload`
  - 本模块暂不支持自动修改SRS配置文件
  - 建议使用ZLMediaKit获得完整功能

- **MySQL** (数据存储)
  - 表：`gv_stream_proxies`

- **Redis** (DAO缓存)
  - 连接：`dao-cache`

---

## 部署说明

### 1. 运行迁移

```bash
bin/phpmig migrate
```

### 2. 重启服务

```bash
php start.php restart
```

### 3. 验证

```bash
# 检查路由
php webman route:list | grep stream-prox

# 检查进程
php start.php status | grep -E "StreamProxy|AutoRecord"
```

### 4. 环境变量（可选）

在 `.env` 中配置：

```bash
# 启用健康检查（默认：1）
ENABLE_STREAM_PROXY_HEALTH_CHECK=1

# 启用自动重连（默认：1）
ENABLE_STREAM_PROXY_AUTO_RECONNECT=1
```

---

## 使用示例

### 海康摄像头接入

```bash
# 1. 创建流代理
curl -X POST "http://127.0.0.1:8886/api/admin/stream-proxies" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "门口监控",
    "type": "pull",
    "protocol": "rtsp",
    "source_url": "rtsp://admin:Admin123@192.168.1.100:554/Streaming/Channels/101",
    "media_server_id": "1"
  }'

# 2. 启动
curl -X POST "http://127.0.0.1:8886/api/admin/stream-proxies/1/start"

# 3. 获取播放地址
curl "http://127.0.0.1:8886/api/admin/stream-proxies/1/play-urls"
```

### OBS推流

```bash
# 1. 创建推流代理
curl -X POST "http://127.0.0.1:8886/api/admin/stream-proxies" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "OBS直播",
    "type": "push",
    "protocol": "rtmp",
    "media_server_id": "1"
  }'

# 2. 获取推流地址（stream字段）
# OBS推流地址：rtmp://192.168.1.10:1935/push/{stream}
```

---

## 注意事项

1. **ZLM依赖**：确保ZLMediaKit服务正常运行
2. **端口开放**：ZLM的RTSP/RTMP/HTTP端口需要可访问
3. **独立性**：未修改任何GB28181代码，可安全卸载
4. **协议隔离**：拉流用 `app=proxy`，推流用 `app=push`，与国标 `app=rtp` 隔离

---

## 技术说明

### 流媒体服务器选择

本模块使用流媒体服务器（ZLMediaKit/SRS）的HTTP API，而非直接使用FFmpeg：

#### ZLMediaKit (推荐)
- ✅ 完整的HTTP API（addStreamProxy/delStreamProxy）
- ✅ 动态流代理管理
- ✅ 多协议转换（RTSP/RTMP/HLS/FLV/WebSocket-FLV）
- ✅ 高性能、低延迟
- ✅ 本模块完整支持

#### SRS (部分支持)
- ✅ 查询类HTTP API（streams/clients）
- ✅ 配置文件方式管理流代理（ingest）
- ⚠️ 无动态添加流代理的API
- ⚠️ 需要修改配置文件+reload
- ⚠️ 本模块仅支持查询功能

#### FFmpeg
- ❌ 无HTTP API
- ❌ 需要管理进程
- ❌ 无内置多协议转换
- ✅ 功能强大（转码/滤镜等）
- 📝 ZLM底层可选使用FFmpeg

**结论**：本模块通过ZLM/SRS的HTTP API实现流代理，无需直接操作FFmpeg进程。项目中的 `php-ffmpeg/php-ffmpeg` 包用于其他功能（如录像文件处理）。

---

**版本**: v1.0.0
**创建日期**: 2026-03-06
**维护者**: 后端开发团队
