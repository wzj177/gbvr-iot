# SIP Gateway 集群管理 API 文档

## 概述

SIP Gateway 集群模块支持多网关实例管理，实现设备自动绑定、网关心跳监控、命令路由分发。每个网关实例拥有独立的命令队列，支持 Redis 和 RabbitMQ 两种消息传输方式。

**核心流程**:
1. 管理员通过 Admin API 创建网关实例并配置参数
2. 网关启动时通过 `--gateway-id` 参数拉取完整配置
3. 网关运行期间每 30 秒上报心跳
4. API 端每 60 秒检查，超 90 秒未心跳的网关标记为 inactive
5. 设备注册时自动绑定到对应网关，命令路由到网关专属队列

---

## 数据结构

### SipGateway 对象

```json
{
  "id": 1,
  "gateway_id": "gw-bj-001",
  "gateway_name": "北京网关-01",
  "server_id": "34020000002000000001",
  "server_domain": "3402000000",
  "sip_host": "0.0.0.0",
  "sip_port": 15060,
  "transport": "UDP",
  "public_ip": "10.20.2.95",
  "device_password": "12345678",
  "authentication": true,
  "sip_username": "admin",
  "register_expires": 3600,
  "keepalive_interval": 60,
  "heartbeat_timeout": 180,
  "keepalive_lost_number": 3,
  "catalog_auto_query": true,
  "encoding_type": "GB2312",
  "task_worker_num": 4,
  "timer_interval": 60,
  "max_devices": 10000,
  "broadcast_push_after_ack": true,
  "mq_type": "redis",
  "mq_config": {},
  "redis_config": {
    "host": "127.0.0.1",
    "password": null,
    "port": 6379,
    "database": 11,
    "prefix": "gbvr_iot_gb_gateway_"
  },
  "api_config": {
    "hock_url": "http://127.0.0.1:8886/api/v2/gb/server/hook",
    "pull_url": "http://127.0.0.1:8886/api/v2/gb/devices/pull",
    "token": "xxx"
  },
  "log_level": "INFO",
  "debug": false,
  "status": "active",
  "last_seen_at": "2026-05-19 15:30:00",
  "pid": 12345,
  "ip": "192.168.1.100",
  "device_count": 128,
  "created_at": "2026-05-19 10:00:00",
  "updated_at": "2026-05-19 15:30:00"
}
```

### 字段说明

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int | 自增主键 |
| `gateway_id` | string | 网关唯一标识，启动时通过 `--gateway-id` 指定 |
| `gateway_name` | string | 网关名称 |
| `server_id` | string | 20位国标编码 |
| `server_domain` | string | SIP域（server_id 前10位） |
| `sip_host` | string | SIP监听地址，默认 `0.0.0.0` |
| `sip_port` | int | SIP监听端口，默认 `5060` |
| `transport` | string | SIP传输协议：`UDP` / `TCP` |
| `public_ip` | string | 公网IP，用于NAT穿透 |
| `device_password` | string | 设备统一接入密码 |
| `authentication` | bool | 是否启用 Digest 认证 |
| `sip_username` | string | SIP认证用户名 |
| `register_expires` | int | 设备注册有效期（秒） |
| `keepalive_interval` | int | 心跳间隔（秒） |
| `heartbeat_timeout` | int | 心跳超时时间（秒） |
| `keepalive_lost_number` | int | 心跳丢失次数阈值 |
| `catalog_auto_query` | bool | 设备注册后自动查询目录 |
| `encoding_type` | string | 字符编码：`GB2312` / `UTF-8` |
| `task_worker_num` | int | Task 进程数 |
| `timer_interval` | int | 定时器间隔（秒） |
| `max_devices` | int | 最大设备连接数 |
| `broadcast_push_after_ack` | bool | 广播是否等 ACK 后推流 |
| `mq_type` | string | 消息队列类型：`redis` / `rabbitmq` |
| `mq_config` | object | RabbitMQ 连接配置（JSON） |
| `redis_config` | object | Redis 连接配置（JSON） |
| `api_config` | object | API 回调配置（JSON） |
| `log_level` | string | 日志级别：`DEBUG` / `INFO` / `WARNING` / `ERROR` |
| `debug` | bool | 调试模式 |
| `status` | string | 状态：`active` / `inactive` / `disabled` |
| `last_seen_at` | string | 最后心跳时间 |
| `pid` | int | 网关进程 PID |
| `ip` | string | 网关运行 IP |
| `device_count` | int | 在线设备数 |

### 网关状态流转

```
  创建 → active（正常在线）
         ↓
    心跳超时(>90s) → inactive（离线）
         ↑              ↓
    心跳恢复       手动禁用
         ↑              ↓
       active       disabled
                        ↓
                   手动启用 → active
```

---

## Admin API（管理后台）

所有 Admin API 需要携带管理员 Token。

**Base URL**: `/api/admin/sip-gateways`

---

### 1. 获取网关列表

`GET /api/admin/sip-gateways`

**请求参数**:
```
status          状态筛选: active|inactive|disabled
mq_type         消息队列类型: redis|rabbitmq
gateway_name    网关名称模糊搜索
start           分页起始 (默认0)
limit           每页数量 (默认20)
```

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "list": [/* SipGateway对象数组 */],
    "paginator": {
      "total": 5,
      "start": 0,
      "limit": 20,
      "current_page": 1,
      "total_pages": 1
    }
  }
}
```

---

### 2. 获取网关详情

`GET /api/admin/sip-gateways/{id}`

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {/* SipGateway对象 */}
}
```

---

### 3. 创建网关

`POST /api/admin/sip-gateways`

**请求Body**:
```json
{
  "gateway_id": "gw-bj-001",
  "gateway_name": "北京网关-01",
  "server_id": "34020000002000000001",
  "server_domain": "3402000000",
  "sip_host": "0.0.0.0",
  "sip_port": 15060,
  "transport": "UDP",
  "public_ip": "10.20.2.95",
  "device_password": "12345678",
  "authentication": true,
  "sip_username": "admin",
  "register_expires": 3600,
  "keepalive_interval": 60,
  "heartbeat_timeout": 180,
  "catalog_auto_query": true,
  "encoding_type": "GB2312",
  "task_worker_num": 4,
  "timer_interval": 60,
  "max_devices": 10000,
  "broadcast_push_after_ack": true,
  "mq_type": "redis",
  "redis_config": {
    "host": "127.0.0.1",
    "password": null,
    "port": 6379,
    "database": 11,
    "prefix": "gbvr_iot_gb_gateway_"
  },
  "api_config": {
    "hock_url": "http://127.0.0.1:8886/api/v2/gb/server/hook",
    "pull_url": "http://127.0.0.1:8886/api/v2/gb/devices/pull",
    "token": "xxx"
  },
  "log_level": "INFO",
  "debug": false
}
```

**必填字段**:
- `gateway_id` - 网关唯一标识
- `gateway_name` - 网关名称
- `server_id` - 20位国标编码
- `server_domain` - SIP域

**唯一性约束**:
- `gateway_id` 全局唯一
- `(sip_host, sip_port)` 组合唯一

**响应**:
```json
{
  "code": 0,
  "msg": "创建成功",
  "data": {/* SipGateway对象 */}
}
```

**错误码**:
- `5000305` - 必填参数缺失
- `4003103` - gateway_id 已存在
- `4003104` - sip_host:sip_port 已被占用

---

### 4. 更新网关

`PUT /api/admin/sip-gateways/{id}`

**请求Body**（只需传需要更新的字段）:
```json
{
  "gateway_name": "北京网关-01（更新）",
  "sip_port": 15061,
  "mq_type": "rabbitmq",
  "mq_config": {
    "host": "127.0.0.1",
    "port": 5672,
    "user": "guest",
    "password": "guest",
    "vhost": "/"
  }
}
```

**可更新字段**: 与创建接口相同的所有业务字段。

**响应**:
```json
{
  "code": 0,
  "msg": "更新成功",
  "data": {/* SipGateway对象 */}
}
```

---

### 5. 删除网关

`DELETE /api/admin/sip-gateways/{id}`

**前置条件**: 网关下不能有关联设备。

**响应**:
```json
{
  "code": 0,
  "msg": "删除成功",
  "data": null
}
```

**错误码**:
- `4043101` - 网关不存在
- `4003105` - 网关下存在关联设备，无法删除

---

### 6. 启用/禁用网关

`POST /api/admin/sip-gateways/{id}/toggle`

切换网关状态：
- `active` ↔ `disabled`

**注意**: `inactive` 状态由心跳超时自动标记，不通过此接口切换。心跳恢复后自动回到 `active`。

**响应**:
```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {/* SipGateway对象 */}
}
```

---

### 7. 绑定设备到网关（批量/单个）

`POST /api/admin/sip-gateways/bind`

将一个或多个设备绑定到指定网关。绑定后，设备相关的所有命令（直播、回放、PTZ控制等）将自动路由到对应网关的命令队列。

**请求Body**:
```json
{
  "gateway_id": "gw-bj-001",
  "device_ids": ["34020000001320000001", "34020000001320000002"]
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `gateway_id` | string | 网关唯一标识（必填） |
| `device_ids` | string[] | 设备ID列表（必填，至少1个） |

**响应**:
```json
{
  "code": 0,
  "msg": "绑定完成",
  "data": {
    "success": 2,
    "failed": 0
  }
}
```

**说明**:
- `success` 为成功绑定的设备数量
- `failed` 为绑定失败的设备数量（设备不存在等情况）
- 绑定成功后，设备的 `gateway_id` 字段被更新
- 后续该设备的所有命令（直播、PTZ、回放等）自动路由到 `gb28181:commands:{gateway_id}` 队列

---

### 8. 解绑设备（批量/单个）

`POST /api/admin/sip-gateways/unbind`

将一个或多个设备从其当前网关解绑。解绑后，设备的命令将使用默认队列 `gb28181:commands`。

**请求Body**:
```json
{
  "device_ids": ["34020000001320000001"]
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `device_ids` | string[] | 设备ID列表（必填，至少1个） |

**响应**:
```json
{
  "code": 0,
  "msg": "解绑完成",
  "data": {
    "success": 1,
    "failed": 0
  }
}
```

---

## 命令路由与网关绑定检查

### 自动 gateway_id 注入

所有通过 `Gb28181Client::sendCommand()` 发送的命令（直播、PTZ、回放、设备控制等）会自动注入 `gateway_id`：

1. 如果调用方显式传入 `gateway_id`，使用传入值
2. 如果未传入，通过解析器自动从数据库查找设备的 `gateway_id` 字段
3. 如果设备未绑定网关（`gateway_id` 为 null），使用默认队列 `gb28181:commands`

**这意味着**: 前端调用直播、PTZ 等接口时，无需关心网关路由，系统自动根据设备绑定关系将命令发送到正确的网关队列。

### 前端设备管理集成建议

在设备管理页面，建议增加以下功能：

1. **设备列表增加「所属网关」列** — 显示 `gateway_id` 或 `gateway_name`
2. **单个绑定** — 在设备操作菜单中增加「绑定网关」，选择网关后调用 `POST /sip-gateways/bind`
3. **批量绑定** — 设备列表多选后，批量选择网关进行绑定
4. **解绑** — 设备操作菜单中增加「解绑网关」，调用 `POST /sip-gateways/unbind`
5. **按网关筛选设备** — 设备列表增加「网关」筛选条件，按 `gateway_id` 过滤

---

## Gateway API（网关内部调用）

网关启动时自动注册、拉取配置和运行中心跳上报的接口。通过 `X-Token` 头认证。

**Base URL**: `/api/v2/gb/gateway`

---

### 9. 网关自动注册

`POST /api/v2/gb/gateway/register`

网关启动时自动调用此接口进行完整注册。如果 `gateway_id` 已存在，更新全部配置字段（包括认证、队列、API回调等）；如果不存在，自动创建新记录。

**请求头**:
```
X-Token: <api_token>
```

**请求Body**:
```json
{
  "gateway_id": "gw-bj-001",
  "gateway_name": "北京网关-01",
  "server_id": "34020000002000000001",
  "server_domain": "3402000000",
  "sip_host": "0.0.0.0",
  "sip_port": 15060,
  "transport": "UDP",
  "public_ip": "10.20.2.95",
  "device_password": "12345678",
  "authentication": true,
  "sip_username": "admin",
  "register_expires": 3600,
  "keepalive_interval": 60,
  "heartbeat_timeout": 180,
  "keepalive_lost_number": 3,
  "catalog_auto_query": true,
  "encoding_type": "GB2312",
  "task_worker_num": 4,
  "timer_interval": 60,
  "max_devices": 10000,
  "broadcast_push_after_ack": true,
  "mq_type": "redis",
  "mq_config": {},
  "redis_config": {
    "host": "127.0.0.1",
    "password": null,
    "port": 6379,
    "database": 11,
    "prefix": "gbvr_iot_gb_gateway_"
  },
  "api_config": {
    "hock_url": "http://127.0.0.1:8886/api/v2/gb/server/hook",
    "pull_url": "http://127.0.0.1:8886/api/v2/gb/devices/pull",
    "token": "xxx"
  },
  "log_level": "INFO",
  "debug": false,
  "pid": 12345,
  "ip": "192.168.1.100"
}
```

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `gateway_id` | string | 是 | 网关唯一标识 |
| `gateway_name` | string | 否 | 网关名称 |
| `server_id` | string | 否 | 20位国标编码 |
| `server_domain` | string | 否 | SIP域 |
| `sip_host` | string | 否 | SIP监听地址 |
| `sip_port` | int | 否 | SIP监听端口 |
| `transport` | string | 否 | SIP传输协议：`UDP` / `TCP` |
| `public_ip` | string | 否 | 公网IP（NAT穿透） |
| `device_password` | string | 否 | 设备统一接入密码 |
| `authentication` | bool | 否 | 是否启用 Digest 认证 |
| `sip_username` | string | 否 | SIP认证用户名 |
| `register_expires` | int | 否 | 设备注册有效期（秒） |
| `keepalive_interval` | int | 否 | 心跳间隔（秒） |
| `heartbeat_timeout` | int | 否 | 心跳超时时间（秒） |
| `keepalive_lost_number` | int | 否 | 心跳丢失次数阈值 |
| `catalog_auto_query` | bool | 否 | 注册后自动查询目录 |
| `encoding_type` | string | 否 | 字符编码：`GB2312` / `UTF-8` |
| `task_worker_num` | int | 否 | Task进程数 |
| `timer_interval` | int | 否 | 定时器间隔（秒） |
| `max_devices` | int | 否 | 最大设备连接数 |
| `broadcast_push_after_ack` | bool | 否 | 广播是否等ACK后推流 |
| `mq_type` | string | 否 | 消息队列类型：`redis` / `rabbitmq` |
| `mq_config` | object | 否 | RabbitMQ 连接配置 |
| `redis_config` | object | 否 | Redis 连接配置（不含 queue_name） |
| `api_config` | object | 否 | API 回调配置 |
| `log_level` | string | 否 | 日志级别 |
| `debug` | bool | 否 | 调试模式 |
| `pid` | int | 否 | 网关进程 PID |
| `ip` | string | 否 | 网关运行 IP |

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {/* SipGateway对象 */}
}
```

**注意**:
- 以 `gateway_id` 为唯一标识做 upsert
- **已存在时**：更新全部上报字段（含 device_password、authentication、redis_config、api_config、mq_config 等），同时更新 status=active、last_seen_at
- **不存在时**：自动创建完整记录，未提供的字段使用系统默认值
- `redis_config` 中无需传 `queue_name`，API 端自动生成 `gb28181:commands:{gateway_id}`

---

### 10. 拉取网关配置

`GET /api/v2/gb/gateway/config?gateway_id=xxx`

网关启动时调用此接口获取完整运行配置。

**请求参数**:
```
gateway_id      网关唯一标识（必填）
```

**请求头**:
```
X-Token: <api_token>
```

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": {
    "gateway_id": "gw-bj-001",
    "server_id": "34020000002000000001",
    "server_domain": "3402000000",
    "sip_host": "0.0.0.0",
    "sip_port": 15060,
    "transport": "UDP",
    "public_ip": "10.20.2.95",
    "device_password": "12345678",
    "authentication": true,
    "sip_username": "admin",
    "register_expires": 3600,
    "keepalive_lost_number": 3,
    "catalog_auto_query": true,
    "encoding_type": "GB2312",
    "task_worker_num": 4,
    "timer_interval": 60,
    "max_devices": 10000,
    "broadcast_push_after_ack": true,
    "debug": false,
    "log_level": "INFO",
    "mq_type": "redis",
    "mq_config": {},
    "redis_config": {
      "host": "127.0.0.1",
      "password": null,
      "port": 6379,
      "database": 11,
      "prefix": "gbvr_iot_gb_gateway_",
      "queue_name": "gb28181:commands:gw-bj-001"
    },
    "api_config": {
      "hock_url": "http://127.0.0.1:8886/api/v2/gb/server/hook",
      "pull_url": "http://127.0.0.1:8886/api/v2/gb/devices/pull",
      "token": "xxx"
    },
    "heartbeat_timeout": 180,
    "keepalive_interval": 60,
    "check_interval": 60
  }
}
```

**关键字段说明**:
- `redis_config.queue_name` — 网关专属命令队列名，格式为 `gb28181:commands:{gateway_id}`
- `mq_type` — 消息队列类型，决定网关使用 Redis 还是 RabbitMQ 接收命令
- `api_config` — API 回调地址，网关向 API 端推送事件时使用

**错误响应**:
```json
{
  "code": -1,
  "msg": "网关不存在",
  "data": null
}
```

---

### 11. 心跳上报

`POST /api/v2/gb/gateway/heartbeat`

网关运行期间每 30 秒调用此接口上报状态。

**请求头**:
```
X-Token: <api_token>
```

**请求Body**:
```json
{
  "gateway_id": "gw-bj-001",
  "pid": 12345,
  "ip": "192.168.1.100",
  "device_count": 128
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `gateway_id` | string | 网关唯一标识（必填） |
| `pid` | int | 网关进程 PID |
| `ip` | string | 网关运行 IP |
| `device_count` | int | 当前在线设备数 |

**响应**:
```json
{
  "code": 0,
  "msg": "success",
  "data": null
}
```

**心跳机制说明**:
- 网关每 30 秒上报一次
- API 端更新 `last_seen_at`、`pid`、`ip`、`device_count`
- 健康检查进程每 60 秀扫描，`last_seen_at` 超过 90 秒标记为 `inactive`
- 网关恢复心跳后自动回到 `active`

---

## 网关启动方式

### 单机模式（向后兼容）

```bash
php gb28181_server.php
# 或指定 ZLM IP
php gb28181_server.php 192.168.1.10
```

使用 `config/gb28181.php` 本地配置，不启用集群功能。

### 集群模式

```bash
# 方式一：等号传参
php gb28181_server.php --gateway-id=gw-bj-001

# 方式二：空格传参
php gb28181_server.php --gateway-id gw-bj-001
```

**启动流程**:
1. 读取本地 `config/gb28181.php` 作为 bootstrap 配置（获取 API 地址和 Token）
2. 解析 `--gateway-id` 参数
3. 通过 HTTP 请求 `/api/v2/gb/gateway/config?gateway_id=xxx` 拉取完整配置
4. 用远程配置覆盖本地配置（server_id、sip_port、mq_type 等）
5. 创建 ExoSip 实例并启动 SIP 服务器
6. Worker 启动后自动调用 `/api/v2/gb/gateway/register` 注册网关（防重复写入）
7. 根据 `mq_type` 创建对应的 Transport（Redis/RabbitMQ）
8. LongTask 进程订阅网关专属队列 `gb28181:commands:{gateway_id}`

---

## 命令路由机制

### 队列命名规则

| 模式 | 队列名 | 说明 |
|------|--------|------|
| 单机 | `gb28181:commands` | 所有命令共用一个队列 |
| 集群 | `gb28181:commands:{gateway_id}` | 每个网关独立队列 |

### 命令发送流程

```
前端调用接口（直播/PTZ/回放等）
    ↓
Gb28181Service → Gb28181Client::sendCommand()
    ↓
sendCommand() 自动解析 gateway_id:
    1. 检查 params 是否显式传入 gateway_id
    2. 若未传入，调用 gatewayIdResolver 查数据库
    3. 从 devices 表获取 device.gateway_id
    ↓
判断 gateway_id 是否存在
    ├── 有 → 推送到 gb28181:commands:{gateway_id}
    └── 无 → 推送到 gb28181:commands（默认队列）
    ↓
对应网关的 CommandSubscriber 接收
    ↓
通过 pipe message 分发给 Worker 处理
```

### 设备自动绑定

设备 SIP REGISTER 时，网关在 POST hook 中携带 `gateway_id`：

```
设备 → SIP REGISTER → Gateway → POST /api/v2/gb/server/hook {scene: "register", body: {..., gateway_id: "gw-bj-001"}}
    → GBServerHookController::handleRegister()
    → 检测到 gateway_id → bindDeviceToGateway(deviceId, gatewayId)
    → 后续命令自动路由到 gw-bj-001 的专属队列
```

---

## 消息队列配置

### Redis 模式（默认）

```json
{
  "mq_type": "redis",
  "redis_config": {
    "host": "127.0.0.1",
    "password": null,
    "port": 6379,
    "database": 11,
    "prefix": "gbvr_iot_gb_gateway_",
    "queue_name": "gb28181:commands:gw-bj-001"
  }
}
```

Redis Transport 使用 `lPush` 发送命令、`blPop` 阻塞接收。

### RabbitMQ 模式

```json
{
  "mq_type": "rabbitmq",
  "mq_config": {
    "host": "127.0.0.1",
    "port": 5672,
    "user": "guest",
    "password": "guest",
    "vhost": "/"
  }
}
```

RabbitMQ Transport 使用持久化队列和消息，支持自动重连。

---

## 错误码

### 通用错误
| 错误码 | 说明 |
|--------|------|
| 0 | 成功 |
| 5000305 | 必填参数缺失 |
| 5000306 | 参数错误 |
| 5000310 | 数据已存在 |

### SIP网关专用错误
| 错误码 | 说明 |
|--------|------|
| 4043101 | SIP网关不存在 |
| 4003102 | 参数错误 |
| 4003103 | 网关标识已存在 |
| 4003104 | SIP监听地址和端口已存在 |
| 4003105 | 网关下存在关联设备，无法删除 |
| 4033106 | 网关已被禁用 |
| 5003107 | 心跳上报失败 |

---

## 使用场景

### 场景1：部署新的网关实例

```bash
# 1. 通过 Admin API 创建网关
curl -X POST http://127.0.0.1:8886/api/admin/sip-gateways \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "gateway_id": "gw-sh-001",
    "gateway_name": "上海网关-01",
    "server_id": "31020000002000000001",
    "server_domain": "3102000000",
    "sip_port": 15061,
    "mq_type": "redis"
  }'

# 2. 部署网关程序到目标服务器，使用 --gateway-id 启动
php gb28181_server.php --gateway-id=gw-sh-001

# 3. 网关自动拉取配置、注册心跳、开始接收设备
```

### 场景2：切换消息队列为 RabbitMQ

```bash
# 更新网关配置
curl -X PUT http://127.0.0.1:8886/api/admin/sip-gateways/1 \
  -H "Authorization: Bearer TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "mq_type": "rabbitmq",
    "mq_config": {
      "host": "192.168.1.200",
      "port": 5672,
      "user": "gb28181",
      "password": "secret",
      "vhost": "/gb28181"
    }
  }'

# 重启网关生效
php gb28181_server.php --gateway-id=gw-bj-001
```

### 场景3：监控网关状态

```javascript
// 定时轮询网关列表，监控在线状态
const res = await fetch('/api/admin/sip-gateways?status=active', {
  headers: { 'Authorization': 'Bearer TOKEN' }
});
const { data } = await res.json();

data.list.forEach(gw => {
  const lastSeen = new Date(gw.last_seen_at);
  const elapsed = (Date.now() - lastSeen.getTime()) / 1000;
  console.log(`${gw.gateway_name}: ${gw.status}, ${elapsed.toFixed(0)}s前活跃, ${gw.device_count}个设备`);
});

// 输出示例:
// 北京网关-01: active, 15s前活跃, 128个设备
// 上海网关-01: active, 28s前活跃, 56个设备
```

---

## 数据库表

### gv_sip_gateways

网关实例表，存储所有网关的配置和运行状态。

| 列名 | 类型 | 说明 |
|------|------|------|
| id | bigint unsigned | 自增主键 |
| gateway_id | varchar(64) | 网关唯一标识（UK） |
| gateway_name | varchar(100) | 网关名称 |
| server_id | varchar(32) | 20位国标编码 |
| server_domain | varchar(16) | SIP域 |
| sip_host | varchar(45) | SIP监听地址 |
| sip_port | int | SIP监听端口 |
| transport | varchar(10) | SIP传输协议 |
| public_ip | varchar(45) | 公网IP |
| device_password | varchar(100) | 设备接入密码 |
| authentication | tinyint(1) | 是否启用认证 |
| sip_username | varchar(50) | SIP用户名 |
| register_expires | int | 注册有效期(秒) |
| keepalive_interval | int | 心跳间隔(秒) |
| heartbeat_timeout | int | 心跳超时(秒) |
| keepalive_lost_number | int | 心跳丢失次数阈值 |
| catalog_auto_query | tinyint(1) | 注册后自动查询目录 |
| encoding_type | varchar(10) | 字符编码 |
| task_worker_num | int | Task进程数 |
| timer_interval | int | 定时器间隔(秒) |
| max_devices | int | 最大设备数 |
| broadcast_push_after_ack | tinyint(1) | 广播是否等ACK后推流 |
| mq_type | varchar(20) | 消息队列类型 |
| mq_config | text | MQ连接配置JSON |
| redis_config | text | Redis连接配置JSON |
| api_config | text | API回调配置JSON |
| log_level | varchar(10) | 日志级别 |
| debug | tinyint(1) | 调试模式 |
| status | varchar(20) | 状态: active/inactive/disabled |
| last_seen_at | datetime | 最后心跳时间 |
| pid | int | 进程PID |
| ip | varchar(45) | 运行IP |
| device_count | int | 在线设备数 |
| created_at | datetime | 创建时间 |
| updated_at | datetime | 更新时间 |

**索引**:
- `uk_gateway_id` (gateway_id) — 唯一
- `uk_sip_host_port` (sip_host, sip_port) — 唯一
- `idx_status` (status)

### gv_devices.gateway_id

`gv_devices` 表新增 `gateway_id` varchar(64) 列，记录设备绑定到哪个网关实例。

---

**版本**: v1.1.0
**更新日期**: 2026-05-20
