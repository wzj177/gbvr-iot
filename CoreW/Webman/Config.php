<?php

namespace CoreW\Webman;

class Config extends \Webman\Config
{
    /**
     * @param string $key
     * @param $value
     * @return void
     */
    public static function set(string $key, $value)
    {
        $keyArray = explode('.', $key);
        $current = &static::$config;
        foreach ($keyArray as $index => $subKey) {
            // 如果是最后一个键，直接设置值并返回
            if ($index === count($keyArray) - 1) {
                $current[$subKey] = $value;
                return;
            }

            // 如果子键存在，则移动到下一级
            if (isset($current[$subKey])) {
                $current = &$current[$subKey];
            } else {
                // 如果子键不存在，则创建一个空数组或关联数组
                $current[$subKey] = [];
                $current = &$current[$subKey];
            }
        }
        //        $level = count($keyArray);
        //        if ($level == 1) {
        //            if (isset(static::$config[$key])) {
        //                static::$config[$key] = $value;
        //            }
        //            return;
        //        }
        //
        //        if ($level === 2) {
        //            if (isset(static::$config[$keyArray[0]][$keyArray[1]])) {
        //                static::$config[$keyArray[0]][$keyArray[1]] = $value;
        //            }
        //            return;
        //        }
        //
        //
        //        if ($level === 3) {
        //            if (isset(static::$config[$keyArray[0]][$keyArray[1]][$keyArray[12]])) {
        //                static::$config[$keyArray[0]][$keyArray[1]][$keyArray[2]] = $value;
        //            }
        //        }
    }
}