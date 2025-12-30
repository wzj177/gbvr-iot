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
