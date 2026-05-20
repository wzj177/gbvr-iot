<?php


namespace CoreW\LiveProvider\Strategy\Impl;


use CoreW\Business\Constants;
use CoreW\LiveProvider\Strategy\LiveProvider;
use CoreW\LiveProvider\Strategy\LiveProviderStrategy;
use CoreW\Sdk\Ys7Sdk\OpenYs7;

class Ys7Strategy extends LiveProviderStrategy implements LiveProvider
{
    public function searchRecorders($offset, $limit, array $conditions = [], $sort = null, $columns = [])
    {
        $result = $this->getYs7Sdk()->getDeviceList($offset, $limit);
        $total = $result['page']['total'] ?? 0;
        $items = $result['data'] ?? [];
        if (!empty($conditions['nvrIds'])) {
            $items = array_values(array_filter($items, function ($item) use ($conditions) {
                return in_array($item['deviceSerial'], $conditions['nvrIds']);
            }));
            $count = count($items);
            $total = $count;
        }
        return [
            'total' => $total,
            'items' => $items,
        ];
    }

    public function searchCameras($offset, $limit, array $conditions = [], $sort = null, $columns = [])
    {
        $result = $this->getYs7Sdk()->getCameraList($offset, $limit);
        $total = $result['page']['total'] ?? 0;
        $items = $result['data'] ?? [];

        return [
            'total' => $total,
            'items' => $items,
        ];
    }

    public function getVideoRecorder($code)
    {
        $result = $this->getYs7Sdk()->getDeviceInfo($code);

        return $result['data'] ?? null;
    }


    public function getDevices()
    {
        return [];
    }

    public function getCameras()
    {
        return [];
    }


    public function getCamera($code)
    {
        $result = $this->getYs7Sdk()->getCamera($code);
        $data = $result['data'] ?? null;
        if ($data && count($data) > 1) {
            $values = array_values(array_filter($data, function ($item) use ($code) {
                return $item['deviceSerial'] === $code;
            }));
            return $values[0] ?? null;
        }

        return $data;
    }

    public function getAccessToken(array $options = [])
    {
        return $this->getYs7Sdk()->getAccessToken();
    }

    public function getVideoCover($code, $otherParam = null)
    {
        $channelNo = $otherParam['channelNo'] ?? 1;
        try {
            $result = $this->getYs7Sdk()->cameraCapture($code, $channelNo);

            return $result['data']['picUrl'] ?? null;
        } catch (\Exception $e) {
            return null;
        }

    }

    /**
     * @param $code
     * @param array $options
     * @return mixed|string|null
     */
    public function getLiveUrl($code, array $options = [])
    {
        !isset($options['protocol']) && $options['protocol'] = 'http_flv';
        $options['protocol'] = $this->gbProtoToYs7Proto($options['protocol']);
        $result = $this->getYs7Sdk()->getCameraLiveUrl($code, $options);
        $data = $result['data'] ?? [];

        return $data['url'] ?? null;
    }

    /**
     * 开启云台
     *
     * @param string $code
     * @param array $options
     * @return bool|void|null
     */
    public function devicePtzStart(string $code, $options)
    {
        $delayStop = $options['delayStop'] ?? false;
        $delay = $options['delay'] ?? 0.5;
        $direction = $this->gbPtzDirectionToYs7Dire($options['ptzCommandType']);
        if ($delayStop) {
            return $this->getYs7Sdk()->deviceControl($code, $options['channelNo'], $direction, $options['speed'] ?? 1);
        }

        return $this->getYs7Sdk()->devicePtzStart($code, $options['channelNo'], $direction, $options['speed'] ?? 1);
    }

    /**
     *
     * 关闭云台控制
     * @param string $code
     * @param $options
     * @return mixed
     */
    public function devicePtzStp(string $code, $options)
    {
        $direction = $this->gbPtzDirectionToYs7Dire($options['ptzCommandType']);

        return $this->getYs7Sdk()->devicePtzStop($code, $options['channelNo'], $direction);
    }

    public function stopLive($code, array $options = [])
    {
        $this->getYs7Sdk()->disableLiveUrl($code, $options['urlId'] ?? null, $options);

        return true;
    }

    public function countRecorders(array $conditions)
    {
        return 0;
    }

    public function openLiveWithCameras(array $conditions, array $options = [])
    {

    }

    public function activeAndOpenLiveWithCameras(array $conditions, $sort, $offset, $limit, $options = [])
    {
        // TODO: Implement activeAndOpenLiveWithCameras() method.
    }

    public function closeLiveWithCameras(array $conditions, array $options = [])
    {
        // TODO: Implement closeLiveWithCameras() method.
    }

    protected function gbProtoToYs7Proto($gbProto)
    {
        $items = [
            'ezopen'   => 1,
            'hls'      => 2,
            'rtmp'     => 3,
            'http_flv' => 4,
        ];

        return $items[$gbProto] ?? 2;
    }

    protected function gbPtzDirectionToYs7Dire($gbDire)
    {
        //gb 指令：1=上;2=左上;3=右上;4=下;5=左下;6=右下;7=左;8=右;9=聚焦+;10=聚焦-;11=变倍+;12=变倍-;13=光圈开;14=光圈关;
        //萤石云指令：0-上，1-下，2-左，3-右，4-左上，5-左下，6-右上，7-右下，8-放大，9-缩小，10-近焦距，11-远焦距，16-自动控制
        $items = [
            1  => 0,
            2  => 4,
            3  => 6,
            4  => 1,
            5  => 5,
            6  => 7,
            7  => 2,
            8  => 3,
            9  => 11,
            10 => 10,
            11 => 8,
            12 => 9,
        ];

        return $items[$gbDire] ?? $gbDire;
    }

    /**
     * @return OpenYs7
     */
    protected function getYs7Sdk() : OpenYs7
    {
        $params = $this->currentThirdParty['params'] ?? [];
        if ($this->currentThirdParty['live_providers'] === Constants::LIVE_PROVIDER_OPEN_LE_YS7_CHANGE) {
            $params = $params['mixConfig']['ys7'] ?? [];
        }
        $call = $this->CoreW->offsetGet('sip.ys7_sdk');
        /** @var OpenYs7 $sdk */
        $sdk = $call($params, true);
        $sdk->setBiz($this->CoreW);

        return $sdk;
    }
}