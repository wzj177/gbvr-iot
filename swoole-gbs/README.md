# Swoole 版 GB28181 / ONVIF 网关

php-exosip C 扩展版的 Swoole 6.x 协程重写。业务流程与扩展版**完全一致**（同一套业务层代码），
SIP/发现协议栈为自解析实现（不依赖协议框架），并补齐 eXosip 原生提供的协议能力
（对话表、Via received/rport 注入、UDP 重传、Digest 挑战重试、订阅续订）。

## 与扩展版的关系（并行共存）

| | 扩展版（老） | Swoole 版（新） |
|---|---|---|
| 启动 | `php webman gb28181:server start` | `php webman gb28181:swoole start` |
| SIP 引擎 | php-exosip C 扩展（全局 `\ExoSip`） | `Gb28181\GateWay\Swoole\ExoSip`（协程自解析） |
| 业务层 | GB28181Handler / CommandDispatcher / DeviceManager（**同一套，引擎无关化**） | 同左 |
| PID 文件 | config gb28181.php 的 pid_file | `runtime/gb28181_swoole_server.pid` |
| 命令队列 | phpredis 阻塞 BLPOP（Task 进程） | `redis_co` 协程传输（不阻塞调度器） |

业务层已去掉引擎类型标注（duck typing），两版注入各自引擎均可运行，
同一台机器同时装 swoole + exosip 扩展也不冲突。老的 `gb28181:server` / `gb28181_server.php` 保持可用。

## 架构（三条核心流程）

```
                    ┌────────────────────────────────────────────┐
 listen             │  php webman gb28181:swoole start           │
───────────────────►│  ├── ExoSip 引擎（UDP/TCP SIP 自解析）     │
 SIP 15060          │  ├── GB28181Handler 业务层（与扩展版同一套）│
                    │  └── 命令消费 CommandSubscriber 协程        │
 api-to-gateway     │      BLPOP gb28181:commands:priority|normal│
───────────────────►│  php webman onvif:server start             │
 Redis db11         │  ├── WS-Discovery 239.255.255.250:3702     │
 gbvr_iot_gb_       │  └── onvif:commands:priority|normal 消费   │
 gateway_ 前缀      │                                             │
 gateway-to-api     │  GB 上报 25 个 scene（register/device_     │
◄───────────────────┤  catalog/media_ready/session_bye/...）     │
 POST /api/v2/gb/   │  ONVIF 上报 onvif_device_found/            │
 server/hook        │  onvif_cmd_after                           │
                    └────────────────────────────────────────────┘
```

- **master 监督 + worker 协程**：worker 崩溃/内存自保护 exit 后自动重建（等价 eXosip Master re-fork）
- **Worker 串行语义**：SIP 事件 / onTaskFinish / onPipeMessage / tick 经分发队列串行执行
- **Task 协程池**：postTask 的 HTTP hook 回调并发执行（SWOOLE_HOOK_CURL 协程化 curl）

## 关键文件

| 文件 | 说明 |
|---|---|
| `app/command/Gb28181SwooleServer.php` | `gb28181:swoole start\|stop\|restart\|status`（--tcp/--debug/--gateway-id） |
| `app/command/OnvifServer.php` | `onvif:server start\|stop\|restart\|status` |
| `Gb28181Gateway/src/Swoole/ExoSip.php` | SIP 引擎（事务/对话/订阅表、重传、Digest UAC） |
| `Gb28181Gateway/src/Swoole/SipCodec.php` | SIP 编解码 + SDP 解析（含 GB28181 y= 扩展） |
| `Gb28181Gateway/src/Swoole/SipEvent.php` | 事件对象（getter 契约与 exosip.stub.php 一致） |
| `Gb28181Gateway/src/Swoole/CoroutineRedis*.php` | 协程 RESP 客户端 + TransportInterface 实现 |
| `Gb28181Gateway/src/Swoole/Supervisor.php` | 两个命令共用的 master 监督 |
| `Gb28181Gateway/src/Swoole/SimpleLogger.php` | 轻量日志器（rebucca 版复用） |
| `Gb28181Gateway/src/Onvif/` | WS-Discovery / OnvifClient(WSSE) / OnvifGateway |
| `config/onvif.php` | ONVIF 配置（默认复用 gb28181 的 redis/hook） |
| `swoole-gbs/tests/` | sipcodec_test.php（纯 PHP）/ engine_smoke_test.php（需 swoole） |

## 测试步骤（真实摄像头）

### 1. 宿主机直跑（需 ext-swoole，与 exosip 扩展可共存）

```bash
php webman gb28181:swoole start          # 前台；nohup/php-fpm 按需自行后台化
php webman gb28181:swoole status
php webman gb28181:swoole stop
```

### 2. docker（无需宿主机 swoole）

```bash
cd swoole-gbs
docker compose up -d --build gb28181-sip
docker logs -f gb28181-sip
```

前提：宿主机 webman（8886）+ Redis + MySQL 已运行；compose 通过 `host.docker.internal`
回连，服务器部署用环境变量覆盖（`GB_REDIS_HOST` / `GB_API_HOCK_URL` / `GB_API_TOKEN` 等，
见 Gb28181SwooleServer.php）。

### 3. 摄像头配置（与扩展版完全相同）

SIP 服务器 ID `34020000002000000001`，域 `3402000000`，端口 `15060/UDP`，密码 `12345678`。

### 4. 验证点（对照扩展版行为）

| 现象 | 期望 |
|---|---|
| REGISTER | 401 → 摘要重发 → 200；webman 设备表上线（scene=register） |
| Keepalive | MESSAGE 200；DB last_heartbeat_at 更新 |
| 自动 Catalog | 通道入库（scene=device_catalog） |
| 点播 | ZLM 开端口（webman 侧）→ 命令入队 → INVITE → 200 → ACK → media_ready → 出流 |
| 停止/设备 BYE | closeRtpServer + 删会话（scene=session_bye） |
| 心跳超时 | tick（60s）标 expired（scene=device_expired） |
| 命令队列手测 | `redis-cli -n 11 lpush gbvr_iot_gb_gateway_gb28181:commands:normal '{"action":"query_catalog","device_id":"...","channel_id":"...","request_id":"t1","timestamp":<ts>}'` |

### 5. ONVIF

```bash
php webman onvif:server start              # 或 docker compose --profile onvif up -d onvif-gateway
```

摄像头同网段。命令（redis 同库）：

```bash
redis-cli -n 11 lpush gbvr_iot_gb_gateway_onvif:commands:normal \
  '{"action":"onvif_get_profiles","request_id":"t2","timestamp":'$(date +%s)',"params":{"ip":"192.168.x.x","username":"admin","password":"xxx"}}'
```

支持 action：`onvif_discover / onvif_get_device_info / onvif_get_capabilities /
onvif_get_profiles / onvif_get_stream_uri / onvif_get_snapshot_uri / onvif_get_presets /
onvif_ptz_control(continuous_move|stop|absolute_move|goto_preset|set_preset) / onvif_reboot`。
结果统一回 `onvif_cmd_after` scene（webman 侧需补对应 scene 分支）。

## 已删除的旧文件（git 历史可找回）

- `Gb28181Gateway/src/SwooleServer/`（rebucca 自管 ZLM/端口架构，SimpleLogger 已并入 Swoole/）
- `swoole-gbs/gbs-beta.php`、`swoole-gbs/server.php`、`swoole-gbs/onvif-server.php`（由 webman 命令取代）
- `RedisSubscriber.php`（@deprecated）/ `DeviceToServerSubscribeHandler.php`（坏命名空间死代码）/
  `MediaSession.php`（TODO 占位）/ `Libs/Timer.php` / `Transport/SipClient.php`（无引用）
