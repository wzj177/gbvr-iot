# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**GBVR-IoT** is a VR panoramic IoT platform built on Webman (PHP async framework) that combines VR panoramic viewing with IoT device management, specifically for GB28181-2016 video surveillance systems. The platform supports VR-based home decoration visualization and integrated IoT device control.

### Key Technologies
- **Framework**: Webman (built on Workerman for async PHP)
- **Language**: PHP 8.2+
- **Database**: MySQL with Doctrine DBAL (not ORM)
- **Cache**: Redis (dao-cache, api_cache, gb_gateway)
- **IoT Protocol**: GB28181-2016 (Chinese video surveillance standard)
- **Streaming**: ZLMediaKit for RTSP/RTP streaming
- **Frontend**: Vue.js 2.x with photo-sphere-viewer for VR panoramas

## Development Commands

### Starting/Stopping Services

```bash
# Main API server (port 8886)
php start.php start           # Start foreground
php start.php start -d        # Start as daemon
php start.php stop
php start.php restart
php start.php status
php start.php reload          # Reload (graceful restart)

# GB28181 SIP server
./start_gb28181.sh           # Includes health checks and orchestration
./stop_gb28181.sh

# Route management
php webman route:list | grep -E "stats|zlm|device"
```

### Code Generation

```bash
# Generate business layer (Service + DAO)
php webman make:biz -i Goods                    # Basic
php webman make:biz -i Student -s students       # Specify table
php webman make:biz -i Coupon --namespace {plugin}\Business\Coupon

# Add additional DAO to existing Service
php webman make:biz-dao -i Product -d ProductCatalog

# Create VIP member
php webman make:vip

# Check company approvals
php webman company:check
```

### Database Operations

```bash
# Run migrations
bin/phpmig migrate

# System initialization (after fresh install)
php webman system:init
```

## Architecture

### Directory Structure

```
app/
├── admin/                    # Admin panel
│   ├── controller/          # Controllers (extend BaseController)
│   └── config/routes/       # Routes at /admin/* and /admin/api/*
├── api/                     # Public API
│   ├── v1/                 # General APIs (auth, products, VIP, IoT)
│   ├── v2/                 # GB28181-specific APIs
│   └── config/routes/      # Routes at /api/v1/* and /api/v2/*
├── command/                 # Console commands
├── middleware/              # Shared middleware
│   ├── admin/              # Admin auth middleware
│   └── security/           # Security/firewall middleware
└── AbstractController.php   # Base controller with getBiz(), createService()

CoreW/                        # Custom framework layer
├── Bfw.php                  # Service container (extends Pimple)
├── Business/                # Business services (auto-generated)
│   └── {Module}/
│       ├── Service/Impl/    # Service implementations
│       └── Dao/Impl/        # DAO implementations
├── Context/                 # Service providers, autoloader
├── Dao/                     # DAO proxy with caching
├── Provider/                # Service registration
└── Sdk/                     # External SDK wrappers (ZLMediaKit, AMap, etc.)

Gb28181Gateway/              # GB28181 SIP server (separate process)
config/                      # Configuration files
support/                     # Webman helper files
```

### Service Container (Bfw)

The framework uses Pimple as the DI container with custom autoloading:

```php
// Service alias pattern: "Module:ServiceName"
$service = $this->createService('User:UserService');      // Resolves to CoreW\Business\User\Service\Impl\UserServiceImpl
$service = $this->createService('System:SystemService');  // Resolves to CoreW\Business\System\Service\Impl\SystemServiceImpl

// Direct container access
$zlmClient = $this->getBiz()->offsetGet('zlm_sdk');
```

**Service Autoloading**: The `ContainerAutoloader` resolves service aliases using:
- Empty prefix → `CoreW\Business\`
- `{Prefix}` → Looks up in `$this->aliases`
- Final class: `{Namespace}\{Middle}\Service\Impl\{Name}Impl`

**IMPORTANT - Workerman Process Container Access**:
```php
// In Workerman processes (app/process/*), use Core::instance() instead of $this->getBiz()
protected function getBfw(): \CoreW\Bfw
{
    return Core::instance();
}

protected function getStreamProxyService(): StreamProxyService
{
    return $this->getBfw()->service('StreamProxy:StreamProxyService');
}
// ❌ WRONG: Core::$container['service'] (undefined static property)
// ✅ CORRECT: Core::instance()->service('service')
```

### Layered Architecture

1. **Controllers** (`app/*/controller/`): Handle HTTP, call services
2. **Services** (`CoreW/Business/*/Service/Impl/`): Business logic
3. **DAOs** (`CoreW/Business/*/Dao/Impl/`): Database access with caching
4. **Models**: Doctrine entities (not traditional ORM)
5. **Enum** (`CoreW/Business/*/Enums/`): Enumerations

### Route Organization

**Admin routes** (`app/admin/config/routes/index.php`):
- `/admin/auth/*` - Login/logout
- `/admin/api/system/*` - System monitoring APIs
- `/admin/gb28181/*` - GB28181 management UI routes

**API routes** (`app/api/config/routes/api.php`):
- `/api/v1/auth/*` - Public auth (login, register)
- `/api/v1/product/*` - VR products/scenes
- `/api/v1/vip/*` - Member management
- `/api/v1/iot/*` - IoT device queries
- `/api/v2/gb28181/*` - GB28181 device management
- `/api/system/*` - System monitoring (uses Admin controller with admin auth middleware)

### Authentication

Multi-context authentication via `AuthIdentityMiddleware`:

- **Storage**: DB (default) or Redis (set `AUTH_TOKEN_STORAGE='redis'` in `.env`)
- **Handler**: Session (default) or JWT (set `AUTH_TOKEN_HANDLER='jwt'` in `.env`)
- **JWT config**: Supports RSA256, multiple TTLs per context (api, admin, etc.)

**Multi-end auth** in `config/app.php`:
```php
'api' => [
    'auth_ttl' => '7 days',
    'jwt_ttl' => 60,
    'jwt_refresh_ttl' => null,
],
```

### GB28181 Integration

**Architecture**: Separate SIP server process (`Gb28181Gateway/`) that:
- Listens on UDP/TCP port 15060
- Communicates with GB28181 devices (cameras, NVRs)
- Uses Redis queues for command dispatch
- Integrates with ZLMediaKit for media streaming

**Key files**:
- `config/gb28181.php` - SIP server config (server ID, domain, media server IP)
- `Gb28181Gateway/Sip/` - SIP protocol implementation
- `Gb28181Gateway/Command/` - Catalog, device control, PTZ, recording
- `app/api/v2/controller/GB28181DeviceController.php` - HTTP API for device management

**Device flow**: Device → SIP Server → ZLMediaKit → RTSP/HLS/FLV streams

### StreamProxy Module (Non-GB28181 Stream Management)

**Purpose**: Independent module for non-GB28181 cameras (Hikvision/Dahua RTSP) and third-party push streams (OBS/FFmpeg)

**Architecture**: Complete isolation from GB28181 code with:
- Pull mode: Actively fetch RTSP/RTMP streams from cameras
- Push mode: Receive push streams from OBS/FFmpeg via custom stream IDs
- Health check: 30s interval to verify stream online status
- Auto-reconnect: 60s interval to restore offline streams
- Recording integration: Binds with existing `gv_record_plan` table
- Comprehensive logging: All operations logged to `gv_stream_proxy_logs`

**Key files**:
- `CoreW/Business/StreamProxy/` - Complete module (DAO/Service/Exception)
- `app/admin/controller/StreamProxyController.php` - 18 REST API endpoints
- `app/process/StreamProxyHealthCheckProcess.php` - 30s health checks
- `app/process/StreamProxyAutoReconnectProcess.php` - 60s auto-reconnect
- `migrations/20260306000001_create_stream_proxies_table.php` - Main table
- `migrations/20260306000002_create_stream_proxy_logs_table.php` - Log table
- `docs/StreamProxy-API.md` - Complete API documentation

**API Base**: `/api/admin/stream-proxies` (18 endpoints including CRUD, stream control, logging, push/pull URLs)

**Stream ID Customization**: For push-type proxies, users can specify custom `stream` field (alphanumeric/dash/underscore) for OBS configuration. If not provided, auto-generates UUID.

### DAO Caching

When Redis `dao-cache` is enabled, DAO methods starting with these names are cached:
`get`, `find`, `search`, `count`, `create`, `batchCreate`, `batchUpdate`, `batchDelete`, `update`, `wave`, `delete`

**Cache strategies**: Row-level and Table-level via Redis.

## Configuration Files

| File | Purpose |
|------|---------|
| `.env` | Environment variables (database, redis, jwt auth) |
| `config/app.php` | App settings, multi-end auth config |
| `config/database.php` | MySQL connections (single or multi-dB) |
| `config/redis.php` | Redis connections (default, dao-cache, api_cache, gb_gateway) |
| `config/gb28181.php` | SIP server config (20-digit server ID, domain, media server IP) |
| `config/iot.php` | IoT platform drivers (BytV3, BytV4) |
| `config/route.php` | Route loading (includes admin/api route folders) |
| `config/log.php` | Log channel configuration - custom channels must be added here before use |
| `config/process.php` | Background process registration (health checks, auto-reconnect, etc.) |

## Key Constraints & Notes

1. **Framework restart required**: Route changes require `php start.php restart`
2. **No ORM**: Uses Doctrine DBAL, not full ORM. Direct SQL or query builder.
3. **Auto-generation**: Use `php webman make:biz` to generate Service/DAO boilerplate
4. **Doctrine DBAL syntax**:
   ```php
   $biz['db']->fetchOne('SELECT * FROM table WHERE id = ?', [$id]);
   $biz['db']->fetchAll('SELECT * FROM table');
   ```
5. **Service naming matters**: Alias `Module:ServiceName` must match directory structure
6. **GB28181 requires ZLMediaKit**: Streaming won't work without ZLM server running
7. **ImageMagick used**: For processing VR panorama images (needs 4GB+ RAM for large images)

## Frontend Integration

- **Admin UI**: `/admin-ui/` - Vue.js admin panel
- **Frontend**: `/public/front/` - VR panorama viewer
- **API base**: `VITE_API_BASE_URL=http://127.0.0.1:8886/admin` (for admin)

## Important Files to Understand

- `CoreW/Bfw.php` - Service container initialization
- `CoreW/Context/ContainerAutoloader.php` - Service/DAO autoloading
- `CoreW/Provider/DefaultServiceProvider.php` - Service maker functions
- `app/AbstractController.php` - Base controller with `getBiz()`, `createService()`
- `config/route.php` - How routes are loaded from folders
- `Gb28181Gateway/gb28181_server.php` - SIP server entry point



## 开发说明

- service里面的`search` 和`count`基础方法，它们的`condition`是有白名单机制的，需要在对应的dao Impl 里面的`declares`下的`conditions`定义，排序也是一样需要在`orderbys`定义
```php
    public function declares(): array
    {
        return [
            'serializes' => [
            ],
            'orderbys' => [
                'id',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
            ],
        ];
    }
```

## Service、DAO、Controller 编码规范

### Service 层规范

**异常处理：**
- 使用魔术方法直接抛出异常，不要用 `$this->createNewException()`
```php
// 正确写法
throw CommonBizException::ERROR_PARAMETER_MISSING();
throw UserException::EMAIL_INVALID();

// 带自定义消息时
throw new UserException(UserException::TEMPORARY_LOCKED, "密码错误次数过多，账户已被临时锁定");

// 错误写法
$this->createNewException(CommonBizException::ERROR_PARAMETER()); // ❌ 不要这样用
```

**获取 DAO 和 Service：**
```php
protected function getMenuDao(): MenuDao|DaoProxy
{
    return $this->createDao('Menu:MenuDao');
}

protected function getRoleService(): RoleService
{
    return $this->createService('Role:RoleService');
}
```
**CRITICAL**: DAO getter methods MUST return `DaoInterface|DaoProxy` union type because `createDao()` returns a `DaoProxy` wrapper (for caching). Without the union type, PHP will throw a TypeError.

**数据验证：**
```php
// 必填字段验证
if (!ArrayToolkit::requireds($data, ['name', 'code'])) {
    throw CommonBizException::ERROR_PARAMETER_MISSING();
}

// 唯一性验证
$existing = $this->getRoleDao()->getByCode($role['code']);
if (!empty($existing)) {
    throw CommonBizException::ERROR_PARAMETER_DUPLICATE();
}

// 字段过滤（只保留白名单字段）
$fields = ArrayToolkit::parts($data, ['name', 'code', 'data']);
```

### DAO 层规范

**继承和实现：**
```php
class RoleDaoImpl extends AdvancedDaoImpl implements RoleDao
{
    protected $table = 'gv_role';

    // declares 定义白名单
    public function declares(): array
    {
        return [
            'serializes' => [
                'data' => 'json',  // JSON 自动序列化/反序列化
                'roles' => 'delimiter', // 分隔符字符串转数组
            ],
            'orderbys' => [
                'id',
                'createdTime',
            ],
            'conditions' => [
                'id = :id',
                'id IN (:ids)',
                'code = :code',
                'code LIKE :codeLike',
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}
```

**常用查询方法：**
```php
// 主键查询
$this->get($id);

// 字段等值查询
$this->getByFields(['code' => 'ROLE_ADMIN']);
$this->getByCode($code);

// IN 查询
$this->findInField('id', $ids);
$this->findInField('code', $codes);

// 条件查询（需要先在 declares 的 conditions 中定义）
$this->search($conditions, $orderBys, $start, $limit);
$this->count($conditions);
```

**数据库访问：**
```php
// 通过 db() 获取 Doctrine DBAL 连接
$this->db()->fetchOne($sql, $params);
$this->db()->fetchAll($sql, $params);
$this->db()->insert($table, $fields);
$this->db()->update($table, $fields, $criteria);
$this->db()->delete($table, $criteria);
```

### Controller 层规范

**路由参数：**
```php
// 路由参数作为方法参数，不要用 $request->route('id')
public function show(Request $request, $id): Response  // ✅ 正确
{
    $id = (int) $id;
}

public function show(Request $request): Response  // ❌ 错误
{
    $id = (int) $request->route('id');  // 不要这样用
}
```

**响应方法：**
```php
// 成功响应
return $this->createSuccessJsonResponse($data);
return $this->createSuccessJsonResponse($data, '操作成功');
return $this->createSuccessJsonResponse(['id' => $id], '创建成功', 201);

// 错误响应
return $this->createErrorJsonResponse('资源不存在', null, -1, 404);
return $this->createErrorJsonResponse('参数错误');
```

**获取请求数据：**
```php
// POST 数据
$fields = $request->post();
$menuIds = $request->post('menuIds', []);

// GET 数据
$conditions = $request->get();
$start = (int) $request->get('start', 0);

// 当前用户
$user = $this->getCurrentUser();
```

**Service 调用：**
```php
protected function getMenuService(): MenuService
{
    return $this->createService('Menu:MenuService');
}

// 在方法中使用
$menu = $this->getMenuService()->getMenu($id);
```

### 异常类规范

**定义异常常量：**
```php
class CommonBizException extends AbstractBizException
{
    const ERROR_PARAMETER_MISSING = 5000305;
    const ERROR_PARAMETER = 5000306;
    const ERROR_PARAMETER_DUPLICATE = 5000310;

    public function setMessages()
    {
        $this->messages = [
            self::ERROR_PARAMETER_MISSING => '参数缺失，请重试！',
            self::ERROR_PARAMETER => '参数错误，请重试！',
            self::ERROR_PARAMETER_DUPLICATE => '数据已存在，请勿重复操作！',
        ];
    }
}
```

**异常编号规则：**
- `400xxxx` - 请求参数错误
- `403xxxx` - 权限禁止
- `404xxxx` - 资源不存在
- `500xxxx` - 业务逻辑错误

## PHP-ExoSip Extension (Native SIP Server/Client)

**Location**: `exosip.stub.php` (IDE stub file), `/Users/jiechengyang/src/c-app/php-exosip` (C source)

### Overview

PHP-ExoSip is a **C extension** that provides native SIP server/client capabilities for PHP. It wraps the eXosip2 library and provides an event-driven, OOP interface similar to Workerman/Swoole.

**Used by**: `Gb28181Gateway/` - GB28181 SIP server implementation

### Core Classes

#### 1. ExoSip - SIP Server (Event-Driven)

```php
$sip = new ExoSip([
    'host' => '0.0.0.0',
    'port' => 15060,
    'mode' => 'UDP',           // UDP|TCP|ALL
    'task_worker_num' => 4,    // Task process count (optional)
    'timer_interval' => 1000,  // Timer interval in ms (optional)
]);

// Event handlers (assign closures)
$sip->onRegister = fn($event) => handleRegister($event);
$sip->onInvite = fn($event) => handleInvite($event);
$sip->onMessage = fn($event) => handleMessage($event);
$sip->onBye = fn($event) => handleBye($event);
$sip->onResponse = fn($event) => handleResponse($event);

// Master-Worker-Task callbacks
$sip->onTask = fn($taskId, $data) => processTask($taskId, $data);
$sip->onTaskFinish = fn($taskId, $result) => handleTaskResult($taskId, $result);
$sip->onTimer = fn() => processTimeouts();

$sip->run();  // Start event loop (blocks)
```

**Key Methods:**
- `sendInvite(string $toUri, string $sdp, ?array $headers): int` - Returns call_id
- `sendBye(int $callId, int $dialogId): bool`
- `sendMessage(string $to, string $message, ?string $contentType): int`
- `sendResponse(int $tid, int $code, ?string $reason, ?array $headers): bool`
- `sendAck(int $dialogId): bool`
- `addTask(array $data): int` - Post task to Task process (Worker → Task)

**Master-Worker-Task Architecture:**
- **Master**: Process manager (monitors Worker/Task)
- **Worker**: Handles SIP events (non-blocking)
- **Task**: Handles blocking operations (HTTP, DB, Redis)

#### 2. SipEvent - SIP Event Object

```php
// Directly from event (recommended)
$callId = $event->getCallId();        // int: eXosip call_id
$dialogId = $event->getDialogId();    // int: eXosip dialog_id
$tid = $event->getTid();              // int: Transaction ID (for sendResponse)

// URIs
$fromUri = $event->getFromUri();      // string: sip:device@domain
$toUri = $event->getToUri();

// Body and SDP
$body = $event->getBody();            // string|null: Current event's body
$sdp = $event->getSdp();              // array|null: Parsed SDP (auto-validates Content-Type)

// Response info
$code = $event->getCode();            // int: 0 for requests, 200-699 for responses
$expires = $event->getExpires();      // int: Expires header value

// Session (optional, use sparingly)
$session = $event->getSession();      // SipSession|null
```

**Important**: Always use `$event->getCallId()` / `$event->getDialogId()` directly instead of going through `$session`.

#### 3. SipSession - SIP Session (Lightweight Handle)

```php
$session = $event->getSession();
if ($session) {
    $callId = $session->getCallId();      // int: eXosip call_id
    $body = $session->getRawBody();       // string|null: Persistent body across events
    $session->close();                    // Send BYE and cleanup
}
```

**When to use SipSession:**
- ✅ Need to store session for later cleanup (`$session->close()`)
- ✅ Need cross-event body access (`getRawBody()`)
- ❌ Just need IDs → Use `$event->getCallId()` directly

#### 4. ExoSipClient - SIP Client (Optional)

```php
$client = new ExoSipClient([
    'server_ip' => '127.0.0.1',
    'server_port' => 5060,
    'username' => 'device001',
    'password' => '123456',
    'realm' => '3402000000',
    'mode' => 'UDP'
]);

$client->start();
$client->sendRegister();
$client->sendMessage('sip:server@domain', 'Hello!');
$events = $client->processEvents(100);
$client->stop();
```

### GB28181Gateway Integration

**File**: `Gb28181Gateway/gb28181_server.php`

```php
// Initialize SIP server
$sip = new ExoSip([
    'host' => $config['sip_host'],
    'port' => $config['sip_port'],
    'mode' => 'UDP',
    'sipId' => $config['sip_id'],
    'sipRealm' => $config['sip_realm'],
    'task_worker_num' => 4,
]);

// Register handlers
$gb28181Handler = new GB28181Handler($sip, $config);
$sip->onRegister = [$gb28181Handler, 'handleRegister'];
$sip->onMessage = [$gb28181Handler, 'handleMessage'];
$sip->onInvite = [$gb28181Handler, 'handleInvite'];
$sip->onBye = [$gb28181Handler, 'handleBye'];
$sip->onResponse = [$gb28181Handler, 'handleResponse'];

// Task handlers for HTTP/DB operations
$sip->onTask = function($taskId, $data) {
    // Post webhook, save to DB, etc.
    return ['success' => true];
};

$sip->run();
```

### Key Conventions

#### Call Flow: INVITE → 200 OK → ACK

```php
// 1. Send INVITE (Server-initiated)
$callId = $sip->sendInvite($deviceUri, $sdp, ['Subject' => $subject]);
// Save $callId for later BYE

// 2. Receive 200 OK (in onResponse)
$sip->onResponse = function($event) use ($sip) {
    if ($event->getCode() == 200) {
        $dialogId = $event->getDialogId();  // Direct access
        $sdp = $event->getSdp();            // Parse device SDP

        // Extract device info
        $deviceIp = $sdp['connection']['addr'];
        $devicePort = $sdp['medias'][0]['port'];
        $ssrc = $sdp['gb28181']['ssrc'] ?? null;

        // Notify ZLMediaKit
        notifyMediaServer($deviceIp, $devicePort, $ssrc);

        // Send ACK
        $sip->sendAck($dialogId);
    }
};

// 3. Later: Stop streaming
$sip->sendBye($callId, 0);  // dialog_id usually 0 for simple sessions
```

#### SDP Parsing

```php
// Method 1: From event (recommended)
$sdp = $event->getSdp();  // Auto-validates Content-Type

// Method 2: Static parser
$sdp = ExoSip::parseSdp($sdpString);

// Access parsed data
$deviceIp = $sdp['connection']['addr'];
$videoPort = $sdp['medias'][0]['port'];
$protocol = $sdp['medias'][0]['proto'];  // RTP/AVP or TCP/RTP/AVP
$ssrc = $sdp['gb28181']['ssrc'] ?? null;  // GB28181 extension (y= field)
```

**Important SDP field names (native osip2 parser):**
- `connection['addr']` (NOT `address`)
- `medias[0]['proto']` (NOT `transport`)
- `gb28181['ssrc']` (y= field in GB28181)

#### Master-Worker-Task Pattern

```php
// In Worker process (SIP event handler)
$sip->onRegister = function($event) use ($sip) {
    $deviceId = extractDeviceId($event->getFromUri());

    // Post HTTP webhook task (non-blocking)
    $taskId = $sip->addTask([
        'type' => 'webhook',
        'url' => 'http://api.example.com/device/register',
        'data' => ['device_id' => $deviceId]
    ]);

    // Continue handling (don't wait for task)
    $sip->sendResponse($event->getTid(), 200, 'OK');
};

// In Task process
$sip->onTask = function($taskId, $data) {
    if ($data['type'] === 'webhook') {
        $result = file_get_contents($data['url'], false, stream_context_create([
            'http' => ['method' => 'POST', 'content' => json_encode($data['data'])]
        ]));
        return ['success' => true, 'response' => $result];
    }
};

// Back in Worker process (auto-triggered)
$sip->onTaskFinish = function($taskId, $result) {
    if ($result['success']) {
        echo "Task #{$taskId} completed\n";
    }
};
```

### Common Pitfalls

1. **❌ Wrong**: `$event->getBody()` in 200 OK handler to get INVITE SDP
   - **✅ Right**: Use `$event->getSdp()` which gets the current message's SDP (200 OK's SDP)
   - **✅ Alternative**: Parse SDP in INVITE handler and store it

2. **❌ Wrong**: Going through session for IDs: `$event->getSession()->getCallId()`
   - **✅ Right**: Direct access: `$event->getCallId()`

3. **❌ Wrong**: Manually building SDP without `\r\n` line endings
   - **✅ Right**: Use `\r\n` (required by RFC 4566)

4. **❌ Wrong**: Calling blocking operations (HTTP, DB) in event handlers
   - **✅ Right**: Use `addTask()` to offload to Task process

5. **❌ 拒绝过度防御性编程
### Documentation

- **Full API Reference**: `exosip.stub.php` (2077 lines, IDE autocomplete)
- **Detailed Guide**: `docs/php-exosip-extension.md`
- **C Source Code**: `/Users/jiechengyang/src/c-app/php-exosip`
- **GB28181 Implementation**: `Gb28181Gateway/src/Handlers/GB28181Handler.php`