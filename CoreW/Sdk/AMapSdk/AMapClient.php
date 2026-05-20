<?php


namespace CoreW\Sdk\AMapSdk;


use Monolog\Logger;
use CoreW\Sdk\AmapSdk\Libs\CurlHttpClient;
use Biz\AmapSdk\Libs\Response;
use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;
use \InvalidArgumentException;

class AMapClient
{
    const MAX_LOG_SIZE = 52428800;

    const SUCCESS_CODE = 1;

    const FAILED_CODE = -1;

    /**
     * @var int
     */
    protected $connectTimeout = 5;
    /**
     * @var int
     */
    protected $timeout = 5;
    /**
     * @var
     */
    protected $apiUrl;
    /**
     * @var string
     */
    protected $accessKey;

    /**
     * @var bool
     */
    protected $debug = false;
    /**
     * @var LoggerInterface|null
     */
    protected $logger = null;
    /**
     * @var CurlHttpClient
     */
    protected $client;

    /**
     * AKStream constructor.
     *
     * @param array('host' => 'xxx', 'key' => 'xxx', 'timeout' => 15, 'connect_timeout' => 15, 'api_version' => 'v1') $options
     */
    public function __construct(array $options)
    {
        if (empty($options['key']) || empty($options['host'])) {
            throw new InvalidArgumentException('init amap sdk error: must be have key and host field');
        }

        $this->accessKey = $options['key'];
        isset($options['debug']) && $this->debug = $options['debug'];
        $this->apiUrl = $options['host'];
        isset($options['connect_timeout']) && $this->connectTimeout = floatval($options['connect_timeout']);
        isset($options['timeout']) && $this->connectTimeout = floatval($options['timeout']);
        if ($this->debug) {
            $this->initLogger();
        }

        $this->createClient();
    }

    protected function initLogger()
    {
        $logFile = runtime_path() . '/logs/amap-sdk/' . date('Ym') . '/' . date('d') . '.log';
        if (is_file($logFile)) {
            $fileSize = filesize($logFile);
            clearstatcache(true, $logFile);
            $fileSize > self::MAX_LOG_SIZE && unlink($logFile);
        }

        $stream = new StreamHandler($logFile, Logger::DEBUG, true, 0755);
        $this->logger = new Logger('amap-client', [$stream]);
    }

    protected function createClient()
    {
        $this->client = new CurlHttpClient();
        $this->client->setConfig([
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HEADER         => true,
        ]);

        $this->client->setConnectionTimeoutInMillis($this->connectTimeout * 1000);
        $this->client->setSocketTimeoutInMillis($this->timeout * 1000);
    }

    /**
     * @return int
     */
    public function getConnectTimeout()
    {
        return $this->connectTimeout;
    }


    /**
     * @return bool
     */
    public function isDebug()
    {
        return $this->debug;
    }

    /**
     * @return int
     */
    public function getTimeout()
    {
        return $this->timeout;
    }

    /**
     * @param int $connectTimeout
     */
    public function setConnectTimeout(int $connectTimeout) : void
    {
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * @param int $timeout
     */
    public function setTimeout(int $timeout) : void
    {
        $this->timeout = $timeout;
    }

    /**
     * GET请求
     *
     * @param $uri
     * @param array $params
     * @param array $headers
     *
     * @return array
     */
    public function get($uri, $params = [], $headers = [])
    {
        $url = $this->getRequestUrl($uri);
        $headers['Content-Type'] = 'application/json;charset=utf-8';
        $params = $this->mergeQueryParams($params);
        $this->log('Query Params:', $params);
        try {
            $rawResponse = $this->client->get($url, $params, $headers);
            [$rawHeaders, $rawBody] = $this->extractResponseHeadersAndBody($rawResponse);

            $response = new Response($rawHeaders, $rawBody);
            $this->log("[{$uri}] RESPONSE_BODY {$response->getBody()}", [], 'debug');

            return $this->parseResponse($response);
        } catch (\Exception $e) {
            $this->log("[$uri] GET ERROR", [
                'errCode' => $e->getCode(),
                'errInfo' => $e->getMessage(),
            ], 'error');
        }
    }

    /**
     * POST请求
     *
     * @param $uri
     * @param array post params    $data
     * @param array request params $params
     * @param array $headers
     *
     * @return array
     */
    public function post($uri, $data = [], $params = [], $headers = [])
    {
        $url = $this->getRequestUrl($uri);
        $params = $this->mergeQueryParams($params);
        $headers['Content-Type'] = 'application/json;charset=utf-8';
        $this->log('Query Params:', $params);
        try {
            $json = is_array($data) ? json_encode($data) : $data;
            $rawResponse = $this->client->post($url, $json, $params, $headers);
            [$rawHeaders, $rawBody] = $this->extractResponseHeadersAndBody($rawResponse);
            $response = new Response($rawHeaders, $rawBody);

            $this->log('HTTP response.', [
                'statusCode' => $response->getHttpResponseCode(),
                'headers'    => $response->getHeaders(),
                'body'       => strpos($uri, 'GetStreamSnap') !== false ? 'img base64' : $response->getBody(),
            ], $response->getHttpResponseCode() >= 400 ? 'error' : 'debug');

            return $this->parseResponse($response);
        } catch (\Exception $e) {
            $this->log("[$uri] POST ERROR", [
                'errCode' => $e->getCode(),
                'errInfo' => $e->getMessage(),
            ], 'error');
        }
    }

    /**
     * @param $uri
     *
     * @return string
     */
    public function getRequestUrl($uri)
    {
        $url = false !== strrpos($this->apiUrl, '/') ? $this->apiUrl . $uri : $this->apiUrl . '/' . $uri;
        $this->log('Request Url：' . $url, [], 'info');

        return $url;
    }

    /**
     *
     * @param array $params
     *
     * @return array
     */
    protected function mergeQueryParams($params = [])
    {
        $params['key'] = $this->accessKey;

        return $params;
    }

    protected function log($msg, $content = [], $type = 'info')
    {
        if ($this->debug && $this->logger) {
            $this->logger->{$type}($msg, $content);
        }
    }


    protected function extractResponseHeadersAndBody($rawResponse)
    {
        $parts = explode("\r\n\r\n", $rawResponse['content']);
        $rawBody = array_pop($parts);
        $rawHeaders = implode("\r\n\r\n", $parts);

        return [trim($rawHeaders), trim($rawBody)];
    }

    protected function parseResponse(Response $response)
    {
        if ($response->getHttpResponseCode() !== 200) {
            return [
                'code' => self::FAILED_CODE,
                'data' => null,
                'msg'  => '请求失败',
            ];
        }
        $body = $response->getBody();
        if (empty($body)) {
            return [
                'code' => self::FAILED_CODE,
                'data' => null,
                'msg'  => '请求失败',
            ];
        }
        $context = json_decode($body, true);
        if (empty($context)) {
            return [
                'code' => self::FAILED_CODE,
                'data' => null,
                'msg'  => '请求失败',
            ];
        }

        if ($context['status'] == 1) {
            return [
                'code' => self::SUCCESS_CODE,
                'data' => $context,
                'msg'  => 'ok',
            ];
        }

        return [
            'code' => self::FAILED_CODE,
            'data' => null,
            'msg'  => 'ok',
        ];
    }
}