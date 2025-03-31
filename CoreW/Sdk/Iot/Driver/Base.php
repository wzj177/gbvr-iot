<?php

namespace CoreW\Sdk\Iot\Driver;

use app\AbstractController;
use CoreW\Business\BizEnum;
use CoreW\Mail\Logger\ReadableJsonFormatter;
use CoreW\Sdk\Iot\HttpResponse;
use CoreW\Sdk\Iot\IotException;
use CoreW\ToolKits\CURLHttpClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use support\utils\ArrayToolkit;

abstract class Base
{
    const IOT_DEVICE_TYPE_WEATHER = 'weather';
    const IOT_DEVICE_TYPE_SOIL = 'soil';
    const IOT_DEVICE_TYPE_WATER_FERTILIZER = 'water_fertilizer';
    const IOT_DEVICE_TYPE_WATER_QUALITY = 'water_quality';
    const IOT_DEVICE_TYPE_WATER_QUALITY_FLOAT = 'water_quality_float';
    const IOT_DEVICE_TYPE_WATER_RAIN = 'water_rain';
    const IOT_DEVICE_TYPE_ENV_MONITOR_STATION = 'env_monitor_station';
    const IOT_DEVICE_TYPE_PEST = 'pest';
    const IOT_DEVICE_TYPE_SPORE = 'spore';
    const IOT_DEVICE_TYPE_CAMERA = 'camera';
    const IOT_DEVICE_TYPE_INSECTICIDAL_LAMP = 'insecticidal_lamp';
    const IOT_DEVICE_TYPE_POWER_BOX = 'power_box';
    const IOT_DEVICE_TYPE_SMART = 'smart';

    protected string $host;
    /**
     * @var bool
     */
    protected bool $debug = false;


    /**
     * @var int|mixed|null 对外端返回的code
     */
    protected ?int $responseOkCode = AbstractController::BIS_SUCCESS_CODE;

    protected ?int $responseFailCode = AbstractController::BIS_FAILED_CODE;

    /**
     * @var int|null api 接口成功状态码
     */
    protected ?int $apiResultOkCode = 0;
    protected ?int $apiResultFailCode = 1;

    /**
     * @var int
     */
    protected int $connectTimeout = 5;
    /**
     * @var int
     */
    protected int $timeout = 5;

    protected int $maxLogSize = 52428800;
    /**
     * @var LoggerInterface|null
     */
    protected ?LoggerInterface $logger = null;
    /**
     * @var CurlHttpClient
     */
    protected CURLHttpClient $client;

    protected string $appId;
    protected string $appSecret;

    public ?array $apiParam = null;
    public array $apiConfig = [];

    public function __construct(array $config)
    {
        if (empty($config['host'])) {
            throw new \Exception('api host must be set');
        }

        $this->host = $config['host'];
        if (isset($config['debug'])) {
            $this->debug = $config['debug'];
        }
        if (isset($config['successCode'])) {
            $this->responseOkCode = $config['successCode'];
        }
        if (isset($config['failedCode'])) {
            $this->responseFailCode = $config['failedCode'];
        }
        if (isset($config['connectTimeout'])) {
            $this->connectTimeout = $config['connectTimeout'];
        }
        if (isset($config['timeout'])) {
            $this->timeout = $config['timeout'];
        }
        if (isset($config['maxLogSize'])) {
            $this->maxLogSize = $config['maxLogSize'];
        }

        if (isset($config['appId'])) {
            $this->appId = $config['appId'];
        }

        if (isset($config['appSecret'])) {
            $this->appSecret = $config['appSecret'];
        }
        if (!empty($config['param'])) {
            $this->apiParam = $config['param'];
        }
        if (!empty($config['api'])) {
            $this->apiConfig = ArrayToolkit::index($config['api'], 'funCode');
        }

        if ($this->debug) {
            $this->initLogger();
        }

        $this->initClient();
    }

    protected function getIotDeviceTypeItems(): array
    {
        return [
            self::IOT_DEVICE_TYPE_INSECTICIDAL_LAMP => '杀虫灯',
            self::IOT_DEVICE_TYPE_CAMERA => '视频监控',
            self::IOT_DEVICE_TYPE_SPORE => '孢子仪',
            self::IOT_DEVICE_TYPE_PEST => '虫情测报',
            self::IOT_DEVICE_TYPE_ENV_MONITOR_STATION => '环境监测站',
            self::IOT_DEVICE_TYPE_WATER_RAIN => '水雨情',
            self::IOT_DEVICE_TYPE_WATER_QUALITY_FLOAT => '水质浮漂',
            self::IOT_DEVICE_TYPE_WATER_QUALITY => '水质监测',
            self::IOT_DEVICE_TYPE_WATER_FERTILIZER => '水肥机',
            self::IOT_DEVICE_TYPE_WEATHER => '气象监测',
            self::IOT_DEVICE_TYPE_SOIL => '土壤监测',
            self::IOT_DEVICE_TYPE_POWER_BOX => '配电柜',
            self::IOT_DEVICE_TYPE_SMART => '智能设备',
        ];
    }

    protected function getFuncData(string $funcCode, array $params = [])
    {
        $mockData = $this->getFuncMockData($funcCode);
        if (!empty($mockData)) {
            return $mockData;
        }

        $setting = $this->apiConfig[$funcCode];
        if (empty($setting['url'])) {
            return [];
        }

        $params['api_result_key_map'] = !empty($setting['keyMap']) ? json_decode($setting['keyMap'], true) : null;

        return $this->request($setting['url'], $setting['method'], $params);
    }

    /**
     * @param $funcCode
     * @param array $params
     * @return array|mixed|null
     */
    protected function getFuncMockData($funcCode, array $params = [])
    {
        $config = array_merge($this->apiConfig[$funcCode] ?? [], $params);
        if (empty($config)) {
            return null;
        }

        if (strpos($config['param'], 'mockData=') === 0) {
            $paramStr = str_replace('mockData=', '', $config['param']);

            return json_decode($paramStr, true);
        }

        return null;
    }

    /**
     * 设备类型格式化(请使用到 deviceList deviceInfo 接口处理)
     * 统一增加device_type 属性
     * @param $deviceInfo
     * @return null|string
     */
    public function iotDeviceTypeFormat($deviceInfo): ?string
    {
        $deviceType = $this->getDeviceTypeFormat($deviceInfo);
        $deviceTypes = $this->getIotDeviceTypeItems();
        if (!in_array($deviceType, array_keys($deviceTypes))) {
            $typeStr = "";
            foreach ($deviceTypes as $deviceType => $deviceTypeName) {
                $typeStr .= $deviceTypeName . '=' . $deviceType . ',';
            }
            $typeStr = rtrim($typeStr, ',');
            throw new IotException("设备类型格式化不符合规范($typeStr),请调整");
        }

        return $deviceType;
    }

    /**
     *
     * @param array|null $device
     * @return string|null
     */
    abstract protected function getDeviceTypeFormat(array $device): ?string;

    protected function request($url, $method, $params = [], $headers = [])
    {
        $uri = ltrim($this->host) . '/' . ltrim($url, '/');
        $resultKeyMap = null;
        if (isset($params['api_result_key_map'])) {
            if (!empty($params['api_result_key_map']) && is_array($params['api_result_key_map'])) {
                $resultKeyMap = $params['api_result_key_map'];
            }

            unset($params['api_result_key_map']);
        }

        try {
            $rawResponse = 'POST' === $method ? $this->client->post($uri, $params, [], $headers) : $this->client->get($uri, $params, $headers);
            list($rawHeaders, $rawBody) = $this->extractResponseHeadersAndBody($rawResponse);
            $response = new HttpResponse($rawHeaders, $rawBody);
            $this->log("[{$uri}] RESPONSE_BODY {$response->getBody()}", [], 'debug');

            $result = $this->parseResponse($response);
            return $this->afterResponse($resultKeyMap, $result);
        } catch (\Exception $e) {
            $this->log("[$uri] GET ERROR", [
                'errCode' => $e->getCode(),
                'errInfo' => $e->getTraceAsString(),
            ], 'error');
            return [
                'code' => $this->responseFailCode,
                'data' => null,
                'msg' => $e->getMessage()
            ];
        }
    }

    protected function extractResponseHeadersAndBody($rawResponse): array
    {
        $parts = explode("\r\n\r\n", $rawResponse['raw']);
        $rawBody = array_pop($parts);
        $rawHeaders = implode("\r\n\r\n", $parts);

        return array(trim($rawHeaders), trim($rawBody));
    }

    protected function parseResponse(HttpResponse $response): array
    {
        if ($response->getHttpResponseCode() !== 200) {
            return [
                'code' => $this->responseFailCode,
                'data' => [],
                'message' => '请求失败'
            ];
        }
        $body = $response->getBody();
        if (empty($body)) {
            return [
                'code' => $this->responseFailCode,
                'data' => [],
                'message' => '请求失败, 响应报文为空'
            ];
        }

        $context = json_decode($body, true);
        if (empty($context)) {
            return [
                'code' => $this->responseFailCode,
                'data' => [],
                'message' => '请求失败, 响应报文解析失败, 系统只支持标准json格式'
            ];
        }

        if ((int)$context['code'] === $this->apiResultOkCode) {
            return [
                'code' => $this->responseOkCode,
                'data' => $context['data'],
                'message' => 'ok'
            ];
        }

        return [
            'code' => $this->responseFailCode,
            'data' => [],
            'message' => 'error'
        ];
    }

    protected function afterResponse(?array $resultKeyMap, ?array $response): array
    {
        if (!empty($resultKeyMap) && !empty($response['data'])) {
            $arrayType = $this->getDataType($response['data']);
            if ($arrayType === 2) {
                $items = [];
                foreach ($response['data'] as $array) {
                    $item = [];
                    foreach ($array as $key => $value) {
                        if (isset($resultKeyMap[$key])) {
                            $item[$resultKeyMap[$key]] = $value;
                        } else {
                            $item = $array;
                        }
                    }
                    $items[] = $item;
                }
                $response['data'] = $items;
            } else if ($arrayType === 1) {
                foreach ($response['data'] as $key => $value) {
                    if (isset($resultKeyMap[$key])) {
                        $response['data'][$resultKeyMap[$key]] = $value;
                        unset($response['data'][$key]);
                    }
                }
            }
        }

        return $response;
    }

    protected function getDataType($array): int
    {
        if (!is_array($array)) {
            return 0; // 不是数组
        }

        foreach ($array as $element) {
            if (is_array($element)) {
                return 2; // 如果有元素是数组，则为多维数组
            }
        }

        return 1; // 所有元素都不是数组，则为单维数组
    }

    protected function initLogger()
    {
        $logFile = runtime_path() . '/logs/iot-sdk/' . date('Ym') . '/' . date('d') . '.log';
        if (is_file($logFile)) {
            $fileSize = filesize($logFile);
            clearstatcache(true, $logFile);
            $fileSize > $this->maxLogSize && unlink($logFile);
        }

        $stream = new StreamHandler($logFile, Logger::DEBUG, true, 0755);
        $formatter = new ReadableJsonFormatter();
        $stream->setFormatter($formatter);
        $this->logger = new Logger('iot-sdk', [$stream]);
    }

    protected function log($msg, $content = [], $type = 'info')
    {
        if ($this->debug) {
            $this->logger->{$type}($msg, $content);
        }
    }

    protected function initClient()
    {
        $this->client = new CURLHttpClient([
            'Content-Type' => 'application/json; charset=utf-8',
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36'
        ]);
        $this->client->setConfig([
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_HEADER => true,
        ]);
        $this->client->setConnectionTimeoutInMillis($this->connectTimeout * 1000);
        $this->client->setSocketTimeoutInMillis($this->timeout * 1000);
    }
}