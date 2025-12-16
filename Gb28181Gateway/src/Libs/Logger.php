<?php

namespace Gb28181\GateWay\Libs;

/**
 * 统一日志组件
 * 
 * 支持：
 * - 多级别日志（DEBUG/INFO/WARNING/ERROR）
 * - 多输出目标（文件/stdout/stderr）
 * - 可选 Monolog 集成
 * - 线程安全的文件写入
 */
class Logger
{
    private static ?self $instance = null;
    private string $logFile = 'php://stdout';
    private string $minLevel = 'INFO';
    private array $levelPriority = [
        'DEBUG' => 0,
        'INFO' => 1,
        'WARNING' => 2,
        'ERROR' => 3,
    ];
    
    private function __construct(array $config = [])
    {
        $this->logFile = $config['log_file'] ?? 'php://stdout';
        $this->minLevel = $config['min_level'] ?? 'INFO';
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
        if (!$this->shouldLog($level)) {
            return;
        }
        
        $time = date('Y-m-d H:i:s');
        $pid = getmypid();
        $modulePrefix = $module ? "[{$module}] " : '';
        $logLine = "[{$time}] [PID:{$pid}] [{$level}] {$modulePrefix}{$message}\n";
        
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
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
        $this->minLevel = $level;
    }
    
    /**
     * 设置日志文件
     */
    public function setLogFile(string $file): void
    {
        $this->logFile = $file;
    }
}
