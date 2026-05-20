<?php

namespace Gb28181\GateWay\Wrappers;

use Gb28181\GateWay\Libs\Logger;

class CallbackWrapper
{
    /**
     * 安全执行回调函数
     *
     * @param callable $callback 要执行的回调函数
     * @param Logger|null $logger 可选的 Logger 实例（用于记录错误到日志文件）
     * @param mixed ...$args 传递给回调的参数
     * @return mixed 回调的返回值,发生错误时返回null
     */
    public static function safe(callable $callback, ?Logger $logger, ...$args)
    {
        try {
            return $callback(...$args);
        } catch (\Throwable $e) {
            // 构建清晰的错误信息
            $callbackName = is_array($callback)
                ? get_class($callback[0]) . '::' . $callback[1]
                : 'Closure';

            $errorLines = [
                "========================================",
                "[GB28181 Callback Exception]",
                "Callback: {$callbackName}",
                "Exception: " . get_class($e),
                "Message: " . $e->getMessage(),
                "File: {$e->getFile()}:{$e->getLine()}",
                "Stack Trace:",
                $e->getTraceAsString(),
                "========================================",
            ];

            $errorMsg = implode("\n", $errorLines);

            // 如果有 logger，记录到日志文件
            if ($logger) {
                $logger->error($errorMsg);
            } else {
                // 否则使用 error_log
                error_log($errorMsg);
            }

            // 输出到 stderr (便于前台调试)
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, "\033[31m" . $errorMsg . "\033[0m\n");
            }

            return null;
        }
    }

    /**
     * 包装事件处理器,返回一个安全的闭包
     *
     * @param object $handler 事件处理器对象
     * @param string $method 方法名
     * @param Logger|null $logger 可选的 Logger 实例
     * @return callable 包装后的安全回调
     */
    public static function wrap(object $handler, string $method, ?Logger $logger = null) : callable
    {
        return function (...$args) use ($handler, $method, $logger) {
            return self::safe([$handler, $method], $logger, ...$args);
        };
    }

    /**
     * 批量包装多个事件处理器
     *
     * @param object $handler 事件处理器对象
     * @param array $methods 方法名数组 ['onRegister', 'onMessage', ...]
     * @param Logger|null $logger 可选的 Logger 实例
     * @return array 包装后的回调数组 ['onRegister' => callable, ...]
     */
    public static function wrapAll(object $handler, array $methods, ?Logger $logger = null) : array
    {
        $wrapped = [];
        foreach ($methods as $method) {
            if (method_exists($handler, $method)) {
                $wrapped[$method] = self::wrap($handler, $method, $logger);
            }
        }
        return $wrapped;
    }
}
