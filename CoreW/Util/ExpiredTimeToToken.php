<?php

namespace CoreW\Util;

class ExpiredTimeToToken
{
    private $secretKey;

    public function __construct($secretKey)
    {
        $this->secretKey = $secretKey;
    }

    // 生成 token
    public function generateToken($expired_time)
    {
        $encrypted_data = base64_encode(openssl_encrypt($expired_time, 'aes-256-cbc', $this->secretKey, 0, $this->secretKey));

        return $encrypted_data;
    }

    // 解析 token
    public function decodeToken($token)
    {
        // 使用密钥进行解密
        return openssl_decrypt(base64_decode($token), 'aes-256-cbc', $this->secretKey, 0, $this->secretKey);
    }
}