# Logger 统一日志组件

## 概述

所有网关组件已统一使用 `Gb28181\GateWay\Libs\Logger` 进行日志输出，支持：

- 日志级别过滤（DEBUG/INFO/WARNING/ERROR）
- 模块前缀标识
- PID 追踪
- 线程安全写入
- 灵活的输出目标

## 基本用法

### 1. 获取 Logger 实例

```php
use Gb28181\GateWay\Libs\Logger;

// 默认配置（输出到 stdout，INFO 级别）
$logger = Logger::getInstance();

// 自定义配置
$logger = Logger::getInstance([
    'log_file' => '/var/log/gb28181.log',
    'min_level' => 'DEBUG',
]);
```

### 2. 记录日志

```php
// 通用方法
$logger->log('Device registered', 'INFO', 'GB28181');

// 快捷方法
$logger->debug('Debug info', 'ModuleName');
$logger->info('Normal info', 'ModuleName');
$logger->warning('Warning message', 'ModuleName');
$logger->error('Error occurred', 'ModuleName');
```

### 3. 输出格式

```
[2024-01-15 10:30:45] [PID:12345] [INFO] [GB28181] Device registered successfully
[2024-01-15 10:30:46] [PID:12345] [ERROR] [RedisSubscriber] Connection lost
```

## 在类中使用

### 模式 1：单例模式（推荐用于独立组件）

```php
class MyComponent
{
    private Logger $logger;

    public function __construct(array $config = [])
    {
        $this->logger = Logger::getInstance($config);
    }

    public function doSomething(): void
    {
        $this->logger->info('Operation started', 'MyComponent');
    }
}
```

### 模式 2：封装方法（推荐用于主处理器）

```php
class GB28181Handler
{
    private Logger $logger;

    public function __construct(ExoSip $sipServer, array $config = [])
    {
        $this->logger = Logger::getInstance([
            'log_file' => $config['log_file'] ?? 'php://stdout',
            'min_level' => $config['debug'] ? 'DEBUG' : 'INFO',
        ]);
    }

    private function log(string $message, string $level = 'INFO'): void
    {
        $this->logger->log($message, $level, 'GB28181');
    }

    public function handleEvent(SipEvent $event): void
    {
        $this->log('Event received: ' . $event->getType());
    }
}
```

## 配置选项

### log_file

日志输出目标：

```php
// 标准输出（默认，适合 Docker/Systemd）
'log_file' => 'php://stdout'

// 文件（需要写权限）
'log_file' => '/var/log/gb28181.log'

// 标准错误输出
'log_file' => 'php://stderr'
```

### min_level

最低日志级别（优先级从低到高）：

```php
'min_level' => 'DEBUG'   // 显示所有日志
'min_level' => 'INFO'    // 显示 INFO/WARNING/ERROR（默认）
'min_level' => 'WARNING' // 仅显示 WARNING/ERROR
'min_level' => 'ERROR'   // 仅显示 ERROR
```

## 已集成的组件

所有核心组件已统一使用 Logger：

| 组件 | 模块标识 | 说明 |
|------|---------|------|
| GB28181Handler | GB28181 | 主事件处理器 |
| DeviceManager | DeviceManager | 设备管理 |
| RedisSubscriber | RedisSubscriber | Redis 订阅器 |
| CommandDispatcher | CommandDispatcher | 命令分发器 |
| QuerySender | N/A | 查询发送器（Logger 已注入） |

## 与其他日志系统集成

### 集成 Monolog

```php
use Monolog\Logger as Monolog;
use Monolog\Handler\StreamHandler;

// 方案 1：双写（推荐）
$monolog = new Monolog('gb28181');
$monolog->pushHandler(new StreamHandler('/var/log/app.log', Monolog::INFO));

$logger = Logger::getInstance(['log_file' => 'php://stdout']);
$logger->info('Message'); // 输出到 stdout
$monolog->info('Message'); // 输出到 /var/log/app.log

// 方案 2：通过 postTask 转发到 API
// API 端使用 Monolog 统一处理所有日志
```

### 集成 Syslog

```php
// 输出到 syslog（需系统支持）
$logger = Logger::getInstance(['log_file' => '/dev/log']);

// 或在启动脚本中重定向：
// php server.php 2>&1 | logger -t gb28181
```

## 性能考虑

### 1. 避免过度日志

```php
// ❌ 每秒输出大量日志
foreach ($devices as $device) {
    $logger->debug("Processing device {$device->getId()}");
}

// ✅ 批量汇总
$logger->info("Processing " . count($devices) . " devices");
```

### 2. 使用级别过滤

```php
// 生产环境
$logger = Logger::getInstance(['min_level' => 'INFO']);

// 开发环境
$logger = Logger::getInstance(['min_level' => 'DEBUG']);
```

### 3. 条件日志

```php
if ($this->config['debug']) {
    $this->logger->debug("Detailed info");
}
```

## 故障排查

### 日志文件权限

```bash
# 确保 PHP 进程有写权限
sudo chown www-data:www-data /var/log/gb28181.log
sudo chmod 644 /var/log/gb28181.log
```

### 日志未输出

```php
// 检查最低级别
Logger::getInstance(['min_level' => 'DEBUG']); // 临时调试

// 检查输出目标
Logger::getInstance(['log_file' => 'php://stdout']); // 直接看终端

// 检查文件句柄
error_log(print_r($logger, true)); // 查看内部状态
```

### 多进程日志乱序

Logger 使用 `FILE_APPEND | LOCK_EX` 保证原子写入，但多进程时间戳可能交错：

```
[10:30:45] [PID:123] Message 1
[10:30:44] [PID:456] Message 2  ← 时间早但后写入
```

解决方案：
1. 按 PID 分析日志流
2. 使用集中式日志系统（ELK/Loki）
3. 通过 Redis 队列汇总后写入

## 示例：完整配置

```php
// config.php
return [
    'log_file' => getenv('LOG_FILE') ?: 'php://stdout',
    'min_level' => getenv('LOG_LEVEL') ?: 'INFO',
    'debug' => getenv('DEBUG') === 'true',
];

// server.php
$config = require 'config.php';
$logger = Logger::getInstance($config);

$handler = new GB28181Handler($sipServer, $config);
$handler->run();
```

```bash
# .env
LOG_FILE=/var/log/gb28181.log
LOG_LEVEL=DEBUG
DEBUG=true
```

## 迁移说明

之前的日志调用已全部迁移：

```php
// 旧代码
echo "[GB28181] Device registered\n";
file_put_contents('php://stdout', "[DeviceManager] Heartbeat timeout\n", FILE_APPEND);
error_log("[CommandDispatcher] Invalid command");

// 新代码
$this->logger->info('Device registered', 'GB28181');
$this->logger->warning('Heartbeat timeout', 'DeviceManager');
$this->logger->error('Invalid command', 'CommandDispatcher');
```

所有旧的 `echo`、`file_put_contents`、`error_log` 调用均已替换为统一的 Logger 调用。
