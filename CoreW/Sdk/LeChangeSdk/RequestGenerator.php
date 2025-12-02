<?php

namespace CoreW\Sdk\LeChangeSdk;

class RequestGenerator
{
    private $appSecret;

    private static $instance;

    private function __construct(string $appSecret)
    {
        $this->appSecret = $appSecret;
    }

    private function __clone()
    {
    }

    public static function getInstance(string $appSecret): RequestGenerator
    {
        if (!self::$instance) {
            self::$instance = new static($appSecret);
        }

        return self::$instance;
    }

    public function generate(): array
    {
        $params = [
            'time' => time(),
            'nonce' => $this->generateNonce(),
            'id' => time(),
        ];

        $params['sign'] = $this->calculateSign(
            $params['time'],
            $params['nonce']
        );

        return $params;
    }

    private function generateNonce(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Exception $e) {
            // 生成16位随机字符串
            return substr(str_shuffle(), 0, 16);
        }
    }

    private function calculateSign(int $timestamp, string $nonce): string
    {
        $str = "time:{$timestamp},nonce:{$nonce},appSecret:{$this->appSecret}";

        return md5($str);
    }
}