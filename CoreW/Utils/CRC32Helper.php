<?php

namespace CoreW\Utils;

class CRC32Helper
{
    /**
     * 计算字符串的CRC32值
     *
     * @param string $str
     * @return int
     */
    public static function getCRC32(string $str): int
    {
        return crc32($str) & 0xffffffff;
    }
}