<?php

namespace Gb28181\GateWay\Libs;

/**
 * 统一日志组件
 *
 * 支持：
 * - 多级别日志（DEBUG/INFO/WARNING/ERROR）
 * - 多输出目标（文件/stdout/stderr）
 * - 按日期自动轮转日志文件
 * - 自动清理过期日志文件
 * - 线程安全的文件写入
 */
class Logger
{
    private static ?self $instance = null;

    /** @var string 日志文件基础路径（用于日期轮转）或 php://stdout */
    private string $logFile = 'php://stdout';

    /** @var string 最小日志级别 */
    private string $minLevel = 'INFO';

    /** @var int 日志文件最大保留天数 (0=不清理) */
    private int $maxDays = 30;

    /** @var string 当前日期（用于检测日期切换） */
    private string $currentDate = '';

    /** @var string 当前实际写入的文件路径 */
    private string $currentLogPath = '';

    /** @var bool 是否为文件输出模式（非 stdout/stderr） */
    private bool $isFileMode = false;

    /** @var int 上次清理过期日志的时间戳 */
    private int $lastCleanupTime = 0;

    private array $levelPriority = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
    ];

    private function __construct(array $config = [])
    {
        $this->logFile = $config['log_file'] ?? 'php://stdout';
        $this->minLevel = strtoupper($config['min_level'] ?? 'INFO');
        $this->maxDays = (int)($config['max_days'] ?? 30);
        $this->isFileMode = !str_starts_with($this->logFile, 'php://');

        if ($this->isFileMode) {
            $this->ensureLogDirectory();
            $this->refreshLogPath();
        } else {
            $this->currentLogPath = $this->logFile;
        }
    }

    /**
     * 获取单例
     */
    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /**
     * 写入日志
     */
    public function log(string $message, string $level = 'INFO', string $module = ''): void
    {
        $level = strtoupper($level);
        if (!$this->shouldLog($level)) {
            return;
        }

        // 检查日期切换（仅文件模式）
        if ($this->isFileMode) {
            $today = date('Y-m-d');
            if ($today !== $this->currentDate) {
                $this->refreshLogPath();
                $this->cleanupOldLogs();
            }
        }

        $time = date('Y-m-d H:i:s');
        $pid = getmypid();
        $modulePrefix = $module ? "[{$module}] " : '';
        $logLine = "[{$time}] [PID:{$pid}] [{$level}] {$modulePrefix}{$message}\n";

        file_put_contents($this->currentLogPath, $logLine, FILE_APPEND | LOCK_EX);
    }

    /**
     * DEBUG 级别日志
     */
    public function debug(string $message, string $module = ''): void
    {
        $this->log($message, 'DEBUG', $module);
    }

    /**
     * INFO 级别日志
     */
    public function info(string $message, string $module = ''): void
    {
        $this->log($message, 'INFO', $module);
    }

    /**
     * WARNING 级别日志
     */
    public function warning(string $message, string $module = ''): void
    {
        $this->log($message, 'WARNING', $module);
    }

    /**
     * ERROR 级别日志
     */
    public function error(string $message, string $module = ''): void
    {
        $this->log($message, 'ERROR', $module);
    }

    /**
     * 判断是否应该记录该级别日志
     */
    private function shouldLog(string $level): bool
    {
        $currentPriority = $this->levelPriority[$level] ?? 1;
        $minPriority = $this->levelPriority[$this->minLevel] ?? 1;
        return $currentPriority >= $minPriority;
    }

    /**
     * 设置最小日志级别
     */
    public function setMinLevel(string $level): void
    {
        $this->minLevel = strtoupper($level);
    }

    /**
     * 设置日志文件
     */
    public function setLogFile(string $file): void
    {
        $this->logFile = $file;
        $this->isFileMode = !str_starts_with($file, 'php://');

        if ($this->isFileMode) {
            $this->ensureLogDirectory();
            $this->refreshLogPath();
        } else {
            $this->currentLogPath = $file;
        }
    }

    /**
     * 刷新日志文件路径（基于当前日期）
     *
     * 例如:
     *   base: /path/to/logs/gb28181.log
     *   result: /path/to/logs/gb28181-2026-02-11.log
     */
    private function refreshLogPath(): void
    {
        $this->currentDate = date('Y-m-d');
        $this->currentLogPath = $this->buildDatedLogPath($this->currentDate);
    }

    /**
     * 根据日期构建日志文件路径
     *
     * @param string $date 日期字符串 (Y-m-d)
     * @return string 带日期的日志文件路径
     */
    private function buildDatedLogPath(string $date): string
    {
        $info = pathinfo($this->logFile);
        $dir = $info['dirname'];
        $name = $info['filename'];
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';

        return $dir . DIRECTORY_SEPARATOR . $name . '-' . $date . $ext;
    }

    /**
     * 确保日志目录存在
     */
    private function ensureLogDirectory(): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * 清理过期日志文件
     *
     * 每天最多执行一次，删除超过 maxDays 天的日志文件
     */
    private function cleanupOldLogs(): void
    {
        if ($this->maxDays <= 0) {
            return;
        }

        $now = time();

        // 每天最多清理一次
        if ($now - $this->lastCleanupTime < 86400) {
            return;
        }

        $this->lastCleanupTime = $now;

        try {
            $info = pathinfo($this->logFile);
            $dir = $info['dirname'];
            $name = $info['filename'];
            $ext = isset($info['extension']) ? '.' . $info['extension'] : '';

            // 匹配格式: {name}-YYYY-MM-DD{ext}
            $pattern = $dir . DIRECTORY_SEPARATOR . $name . '-*' . $ext;
            $files = glob($pattern);

            if (!$files) {
                return;
            }

            $cutoffDate = date('Y-m-d', strtotime("-{$this->maxDays} days"));

            foreach ($files as $file) {
                $basename = basename($file);
                // 从文件名中提取日期部分
                if (preg_match('/-(\d{4}-\d{2}-\d{2})' . preg_quote($ext, '/') . '$/', $basename, $matches)) {
                    $fileDate = $matches[1];
                    if ($fileDate < $cutoffDate) {
                        @unlink($file);
                    }
                }
            }
        } catch (\Throwable $e) {
            // 清理失败不影响正常日志功能
        }
    }
}
