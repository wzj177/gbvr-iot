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