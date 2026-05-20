<?php

namespace CoreW\Sdk\Iot\Driver;

use CoreW\Business\BizEnum;
use Illuminate\Support\Str;

class BytV4 extends Base implements IotInterface
{
    public function deviceCatalogs(array $params = [])
    {
        return $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_DEVICE_CATALOG, $params);
    }

    public function deviceList(array $params = [])
    {
        return $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_DEVICE_LIST, $params);
    }


    public function deviceInfo(?string $deviceCode, array $params = [])
    {
        $params['_id'] = $deviceCode;
        $result = $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_DEVICE_INFO, $params);
        if (!empty($result['data'])) {
            $result['data']['device_full_id'] = $deviceCode;
            $result['data']['device_type'] = $this->iotDeviceTypeFormat($result['data']);
        }

        return $result;
    }

    protected function getDeviceTypeFormat(array $device) : ?string
    {
        [$catalog, $id] = explode('_', $device['device_full_id']);
        $deviceTypeMap = [
            2 => self::IOT_DEVICE_TYPE_WEATHER,
            3 => self::IOT_DEVICE_TYPE_SOIL,
            4 => self::IOT_DEVICE_TYPE_PEST,
            5 => self::IOT_DEVICE_TYPE_CAMERA,
            6 => self::IOT_DEVICE_TYPE_SMART,
        ];

        return $deviceTypeMap[$catalog] ?? null;
    }

    public function deviceRealData(?string $deviceCode, array $params = [])
    {
        $params['_id'] = $deviceCode;
        $result = $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_DEVICE_REAL_DATA, $params);
        if (!empty($result['data'])) {
            $deviceType = $this->getDeviceTypeFormat([
                'device_full_id' => $deviceCode,
            ]);
            if (in_array($deviceType, [self::IOT_DEVICE_TYPE_WEATHER, self::IOT_DEVICE_TYPE_SOIL])) {
                $result['data']['lasted_data_time'] = $result['data']['time'];
            } else if (self::IOT_DEVICE_TYPE_PEST === $deviceType) {
                $result['data']['lasted_data_time'] = $result['data']['abstract']['upload_time'];
            } else {
                $result['data']['lasted_data_time'] = '---'; //date('Y-m-d H:i:s');
            }
        }

        return $result;
    }

    public function deviceHistoryData(?string $deviceCode, array $params = [])
    {
        $params['_id'] = $deviceCode;
        $result = $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_DEVICE_HISTORY_DATA, $params);
        if (!empty($result['data'])) {
            $deviceType = $this->getDeviceTypeFormat([
                'device_full_id' => $deviceCode,
            ]);
            if (in_array($deviceType, [self::IOT_DEVICE_TYPE_WEATHER, self::IOT_DEVICE_TYPE_SOIL])) {
                $xAxisData = array_column($result['data']['data'], 'created_at');
                $seriesData = [];
                if (isset($params['channel_num'])) {
                    $channel = 'channel_' . ($params['channel_num'] < 10 ? "0" . $params['channel_num'] : $params['channel_num']);
                    $seriesData = array_map(function ($item) use ($channel) {
                        return $item[$channel];
                    }, $result['data']['data']);
                }
                $result['data']['data'] = [
                    'xAxisData'  => $xAxisData,
                    'seriesData' => $seriesData,
                ];

            } else if (self::IOT_DEVICE_TYPE_PEST === $deviceType) {
                $result['data'] = [
                    'xAxisData'  => $result['data']['times'],
                    'seriesData' => array_column($result['data']['list'], 'total'),
                ];
            }
        }
        return $result;

    }

    public function cameraLiveUrl(?string $deviceCode, array $params = [])
    {
        $params['_id'] = $deviceCode;
        return $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_CAMERA_LIVE_URL, $params);
    }

    public function gisTilesUrl(array $params = [])
    {
        return $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_GIS_TILES_URL, $params);
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
        if (strpos($setting['url'], '{id}') !== false && isset($params['_id'])) {
            $setting['url'] = str_replace('{id}', $params['_id'], $setting['url']);
            unset($params['_id']);
        }

        return $this->request($setting['url'], $setting['method'], $params);
    }

    public function auth(string $token) : ?array
    {
        if (Str::startsWith($token, "Bearer ") === false) {
            $token = "Bearer " . $token;
        }

        // 保证物联网后台管理登录的token验证有效
        $this->host = str_replace('api', 'admin', $this->host);

        return $this->request('/auth/user', 'GET', [], [
            'Authorization' => $token,
        ]);
    }
}