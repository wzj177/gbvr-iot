<?php

namespace Gb28181\GateWay\Libs;

class Timer
{
    /**
     * Add a timer
     *
     * @param float $seconds Interval in seconds
     * @param callable $callback Callback function
     * @param array $args Arguments for callback
     * @param bool $persistent true = repeat, false = once
     * @return int Timer ID
     */
    public static function add(float $seconds, callable $callback, array $args = [], bool $persistent = true): bool|int
    {
        if (!class_exists('Swoole\Timer')) {
            trigger_error("Timer requires Swoole extension", E_USER_ERROR);
            return false;
        }

        $milliseconds = (int)($seconds * 1000);

        if ($persistent) {
            return \Swoole\Timer::tick($milliseconds, function () use ($callback, $args) {
                call_user_func_array($callback, $args);
            });
        } else {
            return \Swoole\Timer::after($milliseconds, function () use ($callback, $args) {
                call_user_func_array($callback, $args);
            });
        }
    }

    /**
     * Delete a timer
     *
     * @param int $timerId Timer ID
     * @return bool
     */
    public static function del(int $timerId): bool
    {
        if (!class_exists('Swoole\Timer')) {
            return false;
        }

        return \Swoole\Timer::clear($timerId);
    }

    /**
     * Clear all timers
     *
     * @return void
     */
    public static function delAll(): void
    {
        if (!class_exists('Swoole\Timer')) {
            return;
        }

        \Swoole\Timer::clearAll();
    }

    /**
     * Get timer statistics
     *
     * @return array
     */
    public static function info(): array
    {
        if (!class_exists('Swoole\Timer')) {
            return ['count' => 0];
        }

        return \Swoole\Timer::stats();
    }
}