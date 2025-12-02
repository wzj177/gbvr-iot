<?php

namespace CoreW\Sdk\LeChangeSdk;

use support\Redis;

class Controller
{
    protected $api;

    private $_accessToken;

    private $cacheAccessTokenKey;


    public function __construct($appId, $appSecret, $debug = false, $baseUri = null)
    {
        $this->cacheAccessTokenKey = "blive:open:le_change:access_token:{$appId}";
        $this->api = new Api($appId, $appSecret, $debug, $baseUri);
    }

    /**
     * 获取管理员token
     * @return string
     */
    public function accessToken(): ?string
    {
        if ($this->redis()->exists($this->cacheAccessTokenKey)) {
            $this->_accessToken = $this->redis()->get($this->cacheAccessTokenKey);
            return $this->_accessToken;
        }

        try {
            $tokenResult = $this->api->request('accessToken');
            if ($tokenResult['result']['code'] !== "0") {
                return null;
            }

            $tokenTtl = $tokenResult['result']['data']['expireTime'] - 300;
            $this->redis()->setEx($this->cacheAccessTokenKey, $tokenTtl, $tokenResult['result']['data']['accessToken']);
            $this->_accessToken = $tokenResult['result']['data']['accessToken'];

            return $this->_accessToken;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 分页获取设备详情列表
     * @param int $page 页码
     * @param int $pageSize 每页数量
     * @return void|string|array
     */
    public function listDeviceDetailsByPage(int $page = 1, int $pageSize = 50)
    {
        $params = [
            'page' => $page,
            'pageSize' => $pageSize,
        ];

        return $this->authRequest('listDeviceDetailsByPage', $params);
    }

    /**
     * 获取设备信息
     * @param string $deviceId 设备序列号
     * @param int|null $channelId 通道号
     * @return void|string|array
     */
    public function deviceInfo(string $deviceId, ?int $channelId = null)
    {
        $params = [
            'deviceId' => $deviceId,
        ];
        if (is_numeric($channelId)) {
            $params['channelId'] = $channelId;
        }


        $result =  $this->authRequest('listDeviceDetailsByIds', [
            'deviceList' => [$params]
        ]);

        if (!empty($result['data']['deviceList'])) {
            return $result['data']['deviceList'][0];
        }

        return [];
    }

    /**
     * 获取设备在线状态
     * @param string $deviceId 设备序列号
     * @return void|string|array
     */
    public function deviceOnline(string $deviceId)
    {
        $params = [
            'deviceId' => $deviceId,
        ];

        return $this->authRequest('deviceOnline', $params);
    }

    /**
     * 绑定设备直播
     * @param string $deviceId 设备序列号
     * @param int $channelId 通道号
     * @param array $params 其他参数
     * @return void|string|array
     */
    public function bindDeviceLive(string $deviceId, int $channelId = 0, array $params = [])
    {
        $params = array_merge($params, [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
        ]);
        return $this->authRequest('bindDeviceLive', $params);
    }

    /**
     * 解绑设备直播
     * @param string $liveToken
     * @return array
     */
    public function unbindDeviceLive(string $liveToken): array
    {
        $params = [
            'liveToken' => $liveToken,
        ];

        return $this->authRequest('unbindDeviceLive', $params);
    }

    /**
     * 创建设备FLV直播
     * @param string $deviceId 设备序列号
     * @param int $channelId 通道号
     * @param array $params 其他参数
     * @return void|string|array
     */
    public function createDeviceFlvLive(string $deviceId, int $channelId = 0, array $params = [])
    {
        $params = array_merge($params, [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
        ]);
        return $this->authRequest('createDeviceFlvLive', $params);
    }

    /**
     * 删除设备FLV直播
     * @param string $deviceId 设备序列号
     * @param int $channelId 通道号
     * @return void|string|array
     */
    public function deleteDeviceFlvLive(string $deviceId, int $channelId = 0)
    {
        $params = [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
        ];

        return $this->authRequest('deleteDeviceFlvLive', $params);
    }

    /**
     * 查询设备FLV直播
     * @param string $deviceId 设备序列号
     * @param int $channelId 通道号
     * @return void|string|array
     */
    public function queryDeviceFlvLive(string $deviceId, int $channelId = 0)
    {
        $params = [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
        ];
        return $this->authRequest('queryDeviceFlvLive', $params);
    }


    /**
     * 创建设备RTMP直播
     * @param string $deviceId
     * @param int $channelId
     * @param array $params
     * @return array
     */
    public function createDeviceRtmpLive(string $deviceId, int $channelId = 0, array $params = [])
    {
        $params = array_merge($params, [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
        ]);
        return $this->authRequest('createDeviceRtmpLive', $params);
    }

    /**
     * 删除设备RTMP直播
     * @param string $deviceId
     * @param int $channelId
     * @return array
     */
    public function deleteDeviceRtmpLive(string $deviceId, int $channelId = 0)
    {
        $params = [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
        ];
        return $this->authRequest('deleteDeviceRtmpLive', $params);
    }

    /**
     * 查询设备RTMP直播
     * @param string $deviceId
     * @param int $channelId
     * @return array
     */
    public function queryDeviceRtmpLive(string $deviceId, int $channelId = 0)
    {
        $params = [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
        ];
        return $this->authRequest('queryDeviceRtmpLive', $params);
    }

    /**
     * 控制PTZ移动
     * @param string $deviceId 设备序列号
     * @param int $channelId 通道号
     * @param string $operation 操作行为, 0-上，1-下，2-左，3-右，4-左上，5-左下，6-右上，7-右下，8-放大，9-缩小，10-停止
     * @param int $duration 移动持续时间，单位毫秒
     * @return void|string|array
     */
    public function controlMovePTZ(string $deviceId, int $channelId = 0, string $operation, int $duration = 1)
    {
        $params = [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
            'operation' => $operation,
            'duration' => $duration,
        ];
        return $this->authRequest('controlMovePTZ', $params);
    }

    /**
     * 获取直播流信息
     * @param string $deviceId 设备序列号
     * @param int $channelId 通道号
     * @return void|string|array
     */
    public function getLiveStreamInfo(string $deviceId, int $channelId = 0)
    {
        $params = [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
        ];
        return $this->authRequest('getLiveStreamInfo', $params);
    }

    /**
     * 设置设备抓图增强
     * @param string $deviceId 设备序列号
     * @param int $channelId 通道号
     * @return void|string|array
     */
    public function setDeviceSnapEnhanced(string $deviceId, int $channelId = 0)
    {
        $params = [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
        ];
        return $this->authRequest('setDeviceSnapEnhanced', $params);
    }

    /**
     * 获取直播列表
     * @return void|string|array
     */
    public function liveList(int $start = 1, int $end = 50)
    {
        $queryRange = [$start, $end];
        return $this->authRequest('liveList', ['queryRange' => $queryRange]);
    }

    /**
     * 获取直播状态
     * @param string $liveToken 直播token
     * @return void
     */
    public function queryLiveStatus(string $liveToken)
    {
        return $this->authRequest('queryLiveStatus', ['liveToken' => $liveToken]);
    }

    /**
     * 获取Kit Token
     * @param string $deviceId 设备序列号
     * @param string $channelId 通道号
     * @param int $type 0：所有权限；1：实时预览；2：录像回放（云录像+本地录像）；6：云台转动
     * @return array|null
     */
    public function getKitToken(string $deviceId, string $channelId, int $type = 0): ?array
    {
        $params = [
            'deviceId' => $deviceId,
            'channelId' => $channelId,
            'type' => $type,
        ];

        $result = $this->authRequest('getKitToken', $params);
        if ($result['code'] === 0) {
            $result['data']['token'] = $this->_accessToken;
            $result['data']['deviceId'] = $deviceId;
            $result['data']['channelId'] = $channelId;

            return $result['data'];
        }

        return null;
    }

    protected function authRequest(string $url, array $params = [], string $method = 'POST', array $headers = []): array
    {
        $params['token'] = $this->accessToken();

        try {
            $response = $this->api->request($url, $params, $method, $headers);
            if ($response['result']['code'] === 0 || $response['result']['code'] === "0") {
                return [
                    'code' => 0,
                    'msg' => $response['result']['msg'],
                    'data' => $response['result']['data'],
                ];
            }

            return [
                'code' => $response['result']['code'],
                'message' => $response['result']['msg'],
                'data' => [],
            ];
        } catch (\Exception $e) {
            return [
                'code' => -1,
                'message' => "请求 {$url} 失败，" . $e->getTraceAsString(),
                'data' => [],
            ];
        }
    }

    /**
     * redis 缓存类
     * @return \Illuminate\Redis\Connections\Connection
     */
    public function redis(): \Illuminate\Redis\Connections\Connection
    {
        return Redis::connection('sdkCache');
    }
}