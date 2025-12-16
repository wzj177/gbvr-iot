<?php

namespace Gb28181\GateWay\Wrappers;

class CallbackWrapper
{
    /**
     * 安全执行回调函数
     *
     * @param callable $callback 要执行的回调函数
     * @param mixed ...$args 传递给回调的参数
     * @return mixed 回调的返回值,发生错误时返回null
     */
    public static function safe(callable $callback, ...$args)
    {
        try {
            return $callback(...$args);
        } catch (\Throwable $e) {
            // 记录详细的错误信息
            $errorMsg = sprintf(
                "[Callback Error] %s: %s\nFile: %s:%d\nStack trace:\n%s",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
                $e->getTraceAsString()
            );

            error_log($errorMsg);

            // 如果是在CLI模式下,也输出到stderr
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
     * @return callable 包装后的安全回调
     */
    public static function wrap(object $handler, string $method): callable
    {
        return function (...$args) use ($handler, $method) {
            return self::safe([$handler, $method], ...$args);
        };
    }

    /**
     * 批量包装多个事件处理器
     *
     * @param object $handler 事件处理器对象
     * @param array $methods 方法名数组 ['onRegister', 'onMessage', ...]
     * @return array 包装后的回调数组 ['onRegister' => callable, ...]
     */
    public static function wrapAll(object $handler, array $methods): array
    {
        $wrapped = [];
        foreach ($methods as $method) {
            if (method_exists($handler, $method)) {
                $wrapped[$method] = self::wrap($handler, $method);
            }
        }
        return $wrapped;
    }
}
