<?php

namespace CoreW;

class Env
{
    /**
     * 获取环境变量值，并自动转换常见类型
     *
     * @param string $key 环境变量名
     * @param mixed $default 默认值（支持任意类型）
     * @return mixed             返回值（string/int/bool/float/null）
     */
    public static function get(string $key, $default = null)
    {
        $value = getenv($key);

        // 变量未设置
        if ($value === false) {
            return $default;
        }

        // 空字符串视为未设置（可选行为，符合 Laravel 习惯）
        if ($value === '') {
            return $default;
        }

        // 类型自动转换
        return static::convertValue($value, $default);
    }

    /**
     * 获取整数
     */
    public static function int(string $key, int $default = 0): int
    {
        return (int)static::get($key, $default);
    }

    /**
     * 获取浮点数
     */
    public static function float(string $key, float $default = 0.0): float
    {
        return (float)static::get($key, $default);
    }

    /**
     * 获取布尔值（支持 'true', 'false', '1', '0', 'on', 'off' 等）
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $value = static::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (bool)$value;
        }

        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return in_array($lower, ['true', '1', 'on', 'yes', 'y'], true);
        }

        return (bool)$value;
    }

    /**
     * 内部：将字符串值转换为合适的 PHP 类型
     */
    protected static function convertValue(string $value, $default)
    {
        // null
        if ($value === 'null') {
            return null;
        }

        // boolean
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }

        // numeric (int/float)
        if (is_numeric($value)) {
            // 区分整数和浮点
            if (strpos($value, '.') === false && !str_starts_with($value, '0') || $value === '0') {
                return (int)$value;
            }
            return (float)$value;
        }

        // 默认返回原字符串
        return $value;
    }
}