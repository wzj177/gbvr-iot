<?php

namespace Gb28181\GateWay\Traits;

trait CurlTrait
{
    protected function curlPost($url, $data) : bool|string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Token: ' . $this->config['api_hock_token'],
        ]);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // 设置超时 (总时间，应大于连接超时)
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        // 设置连接超时 (仅连接阶段)
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        // 关闭ssl
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($ch);
        curl_close($ch);
        if (curl_errno($ch)) {
            $this->log("CURL Error: " . curl_error($ch), 'ERROR');
        }

        return $result;
    }

    /**
     * 发送GET请求到API
     * @param string $url
     * @return array|null
     */
    protected function curlGet(string $url) : ?array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Token: ' . $this->config['api_hock_token'],
        ]);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            $this->log("CURL GET Error: {$error}", 'ERROR');
            return null;
        }

        if (!$result) {
            return null;
        }

        $data = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log("JSON decode error: " . json_last_error_msg(), 'ERROR');
            return null;
        }

        return $data;
    }
}