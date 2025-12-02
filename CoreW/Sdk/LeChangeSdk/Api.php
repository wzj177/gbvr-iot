<?php

namespace CoreW\Sdk\LeChangeSdk;

class Api
{
    const MAX_LOG_SIZE = 1024 * 1024 * 10;

    private $timeout = 3;

    private $appId;

    private $appSecret;

    private $baseUri = "https://openapi.lechange.cn/openapi";

    private $debug;

    private $logger;


    public function __construct($appId, $appSecret, $debug = false, $baseUri = null)
    {
        $this->debug = $debug;
        $this->appId = $appId;
        $this->appSecret = $appSecret;
        if ($baseUri) {
            $this->baseUri = $baseUri;
        }

        if ($this->debug) {
            $this->logger = new \Monolog\Logger('api');
            $logFile = runtime_path() . '/logs/LeChangeSdk/' . date('Ym') . '/' . date('d') . '.log';
            if (file_exists($logFile)) {
                $fileSize = filesize($logFile);
                clearstatcache(true, $logFile);
                $fileSize > self::MAX_LOG_SIZE && unlink($logFile);
            }

            try {
                $this->logger->pushHandler(new \Monolog\Handler\StreamHandler($logFile));
            } catch (\Exception $e) {
            }
        }
    }


    /**
     * @param int $timeout
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * 请求api
     * @param string $url api route url
     * @param array $params 请求参数
     * @param string $method POST GET
     * @param array $headers 额外的header
     * @return array
     * @throws \Exception
     */
    public function request(string $url, array $params = [], string $method = 'POST', array $headers = []): array
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36'
        ], $headers);
        // token 必须要在 请求params 中设置
        if ('accessToken' !== $url && empty($params['token'])) {
            throw new \Exception('token must be set');
        }

        $systemParams = RequestGenerator::getInstance($this->appSecret)->generate();
        $data = [
            'system' => [
                'ver' => "1.0",
                'appId' => $this->appId,
                'time' => $systemParams['time'],
                'nonce' => $systemParams['nonce'],
                'sign' => $systemParams['sign'],
            ],
            'id' => $systemParams['id'],
            'params' => $params
        ];

        $this->logRequest($method, $url, $data, $headers);

        if (class_exists('GuzzleHttp\Client')) {
            $response = $this->guzzleRequest(strtoupper($method), $url, $data, $headers);
        } else {
            $response = $this->curlRequest(strtoupper($method), $url, $data, $headers);
        }

        $this->logResponse($response);

        return $response;
    }

    private function logRequest($method, $url, $params, $headers)
    {
        if ($this->logger) {
            $this->logger->info(json_encode([
                'method' => $method,
                'url' => $url,
                'params' => $params,
                'headers' => $headers,
            ]));
        }
    }

    private function logResponse($response)
    {
        if ($this->logger) {
            $logContext = [
                'response' => $response,
                'response_code' => $response['result']['code'] ?? 'UNKNOWN'
            ];

            if (($response['result']['code'] ?? 0) === 0) {
                $this->logger->info('API Response', $logContext);
            } else {
                $this->logger->error('API Error', $logContext);
            }
        }
    }

    private function guzzleRequest($method, $url, $params, $headers): array
    {
        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => ltrim($this->baseUri, '/') . '/',
                'timeout' => $this->timeout,
            ]);
            $options = [
                'headers' => $headers,
                'http_errors' => false // 不抛出HTTP异常
            ];

            if ($method === 'GET') {
                $options['query'] = $params;
            } else {
                $options['json'] = $params;
            }

            $fullUrl = $client->getConfig('base_uri') . $url;
            $response = $client->request($method, $url, $options);
            $body = $response->getBody()->getContents();

            return $this->parseResponse($body);
        } catch (\Throwable $e) {
            return $this->buildErrorResponse($e->getCode(), $e->getMessage());
        }
    }

    private function curlRequest($method, $url, $params, $headers): array
    {
        $ch = curl_init();
        $curlOptions = [
            CURLOPT_URL => rtrim($this->baseUri, '/') . '/' . ltrim($url, '/'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        if ($method === 'GET' && !empty($params)) {
            $curlOptions[CURLOPT_URL] .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }

        if ($method !== 'GET') {
            $curlOptions[CURLOPT_CUSTOMREQUEST] = $method;
            if (!empty($params)) {
                $curlOptions[CURLOPT_POSTFIELDS] = json_encode($params);
            }
        }

        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($error) {
            return $this->buildErrorResponse($errno, $error);
        }

        return $this->parseResponse($response);
    }

    protected function formatHeaders($headers): array
    {
        $formattedHeaders = [];
        foreach ($headers as $key => $value) {
            $formattedHeaders[] = "{$key}: {$value}";
        }

        return $formattedHeaders;
    }

    protected function parseResponse(string $body): array
    {
        $result = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->buildErrorResponse(-1, json_last_error_msg(), $body);
        }

        if (!isset($result['result']) || !isset($result['id'])) {
            return $this->buildErrorResponse(-1, 'Invalid response format', $body);
        }

        !isset($result['result']['data']) && $result['result']['data'] = [];

        return $result ?: [];
    }

    protected function buildErrorResponse($code, $message, $body = ''): array
    {
        return [
            'result' => [
                'code' => $code,
                'message' => $message,
                'body' => $body,
            ],
            'id' => null
        ];
    }
}