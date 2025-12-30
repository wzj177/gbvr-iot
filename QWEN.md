# GBVR-IoT Platform Documentation

## Project Overview

This is a VR panoramic IoT platform called `webman-vr-panoramic`, built on the Webman framework. The project combines VR panoramic viewing capabilities with IoT device management, particularly focusing on GB28181-compliant video surveillance systems. It's designed as a VR-based platform for home decoration and IoT integration.

### Key Technologies
- **Framework**: Webman (PHP-based async framework built on Workerman)
- **Language**: PHP 8.2+
- **Database**: MySQL (with Doctrine DBAL)
- **Cache**: Redis
- **IoT Protocol**: GB28181-2016 (video surveillance standard)
- **Streaming**: ZLMediaKit for RTSP/RTP streaming
- **Frontend**: Vue.js 2.x with photo-sphere-viewer4 for VR panoramics

### Architecture Components
- **Web API Layer**: RESTful APIs for VR panoramic management and IoT device integration
- **GB28181 Gateway**: SIP signaling gateway for connecting GB28181 compliant devices
- **IoT Integration**: Multiple IoT driver support (BytV3, BytV4)
- **Streaming Engine**: Integration with ZLMediaKit for media streaming

## Building and Running

### Prerequisites
- PHP >= 8.2
- Composer >= 2.0
- Redis server
- MySQL server
- Memory: At least 4GB (for processing large panoramic images)
- ZLMediaKit server (optional, for streaming)

### Installation Steps

1. **Clone and Install Dependencies**
```bash
composer install -vvv
```

2. **Configure Environment**
```bash
cp .env.example .env
# Edit .env file with your environment parameters
```

3. **Run Database Migrations**
```bash
bin/phpmig migrate
```

4. **Initialize System**
```bash
php webman system:init
```

### Running the Application

1. **Start Main API Server**
```bash
# Normal mode
php start.php start

# Daemon mode
php start.php start -d

# Stop/Restart/Status
php start.php stop
php start.php restart
php start.php status
```

2. **Start GB28181 Server**
```bash
# Using convenience script (includes checks and service orchestration)
./start_gb28181.sh

# Or manually
php gb28181_server.php
```

3. **Stop Services**
```bash
./stop_gb28181.sh
```

### Service Configuration

The system runs on multiple ports:
- **Main API**: http://0.0.0.0:8886
- **GB28181 SIP**: Port 15060 (UDP/TCP, configurable)
- **ZLMediaKit**: Typically http://127.0.0.1:8086

## Development Conventions

### Project Structure
```
app/
├── admin/          # Admin panel controllers
├── api/            # API controllers (v1, v2)
│   ├── v1/         # Version 1 APIs
│   └── v2/         # Version 2 APIs (GB28181 related)
├── command/        # Console commands
├── middleware/     # Request middleware
config/             # Configuration files
Gb28181Gateway/     # GB28181 protocol implementation
CoreW/              # IoT SDK
public/             # Static files
```

### API Endpoints

**Version 1 APIs** (`/api/v1/`):
- Authentication: `/api/v1/auth/*`
- Products (VR scenes): `/api/v1/product/*`
- VIP/Member: `/api/v1/vip/*`
- IoT: `/api/v1/iot/*`

**Version 2 APIs** (`/api/v2/`):
- GB28181 devices: `/api/v2/gb28181/devices/*`
- GB28181 streams: `/api/v2/gb28181/channels/*`
- GB server hooks: `/api/v2/gb/server/hock`

### GB28181 Configuration

Located in `config/gb28181.php`, the GB28181 server handles:
- SIP signaling (UDP/TCP port 5060)
- Device registration and authentication
- Catalog queries for device channels
- Stream control (play, stop, PTZ control)
- Integration with ZLMediaKit for streaming

Key configuration options:
- `server_id`: 20-digit GB28181 device ID
- `server_domain`: 10-digit domain (first 10 digits of server_id)
- `device_password`: Authentication password for devices
- `zlm.media_server_ip`: IP address where devices push RTP streams

### IoT Integration

The system supports multiple IoT platforms via drivers:
- BytV3 (乡亿客物联网V3)
- BytV4 (乡亿客物联网V4)
- Custom drivers can be added

Configuration in `config/iot.php` defines API mappings between IoT functions and internal endpoints.

### 路由
- admin： admin/config/routes/index.php
- api： api/config/routes/index.php
### Development Commands

- Generate business layer: `php webman make:biz -i Goods`
- Generate DAO layer: `php webman make:biz-dao -i Product -d ProductCatalog`
- Create VIP members: `php webman make:vip`
- Check companies: `php webman company:check`

### Authentication

Supports multiple authentication methods:
- Standard session-based authentication
- JWT tokens (configurable via `.env`)
- Multi-end authentication (API, admin, etc.)

## Key Features

1. **VR Panoramic Management**: Create, edit, and share VR panoramic scenes
2. **GB28181 Integration**: Connect and control IP cameras and NVRs using the Chinese video surveillance standard
3. **IoT Device Management**: Support for multiple IoT platforms and protocols
4. **Streaming Integration**: Real-time video streaming via ZLMediaKit
5. **Multi-tenant Support**: Different authentication contexts for API, admin, etc.
6. **Large File Processing**: ImageMagick integration for panoramic image processing
7. **Queue System**: Redis-based queue for background tasks