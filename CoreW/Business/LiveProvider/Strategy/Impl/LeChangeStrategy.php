<?php

namespace CoreW\LiveProvider\Strategy\Impl;


use CoreW\Business\Constants;
use CoreW\LiveProvider\Strategy\LiveProvider;
use CoreW\LiveProvider\Strategy\LiveProviderStrategy;
use CoreW\Sdk\LeChangeSdk\Controller as LeChangeSdk;
use DateTime;
use DateTimeZone;

class LeChangeStrategy extends LiveProviderStrategy implements LiveProvider
{

    public function closeLiveWithCameras(array $conditions, array $options = [])
    {
        // TODO: Implement closeLiveWithCameras() method.
    }

    public function getDevices()
    {
        // TODO: Implement getDevices() method.
    }

    public function getCameras()
    {

    }

    public function searchCameras($offset, $limit, array $conditions = [], $sort = null, $columns = []): array
    {
        $offset === 0 && $offset = 1;
        $limit > 50 && $limit = 50;
        $result = $this->getLeChangeSdk()->listDeviceDetailsByPage($offset, $limit);
        $total = $result['data']['count'] ?? 0;
        $devices = $result['data']['deviceList'] ?? [];
        $channels = [];
        foreach ($devices as $device) {
            $channels = array_merge($channels, $this->mapChannels($device));
        }

        return [
            'total' => $total,
            'items' => $channels,
        ];
    }

    public function getLiveUrl($code, array $options = [])
    {
        $protocol = $options['protocol'] ?? 'hls';
        if (!in_array($protocol, ['hls', 'flv', 'rtmp'])) {
            $protocol = 'hls';
        }
        $quality = $options['quality'] ?? 1;
        $channelId = $options['channelId'] ?? 0;
        //视频清晰度，1-高清（主码流）、2-流畅（子码流）
        $streamId = $quality == 1 ? 0 : 1;
        if ($protocol === 'hls') {
            $queryResult = $this->getLeChangeSdk()->getLiveStreamInfo($code, (int)$channelId);
            $streams = $queryResult['data']['streams'] ?? [];
            if (empty($streams)) {
                $result = $this->getLeChangeSdk()->bindDeviceLive($code, (int)$channelId, [
                    'streamId' => $streamId
                ]);
                $streams = $result['data']['streams'] ?? [];
            }

            $stream = $this->findStreamByStreamId($streams, $streamId);
            return $stream['hls'] ?? null;
        } else if ($protocol === 'flv') {
            $queryResult = $this->getLeChangeSdk()->queryDeviceFlvLive($code, (int)$channelId);
            if (!empty($queryResult['data'])) {
                $stream = $queryResult['data'];

                return $streamId == 0 ? $stream['flvHD'] : $stream['flv'];
            }

            $result = $this->getLeChangeSdk()->createDeviceFlvLive($code, (int)$channelId, [
                'streamId' => $streamId
            ]);
            if (!empty($result['data'])) {
                $stream = $result['data'];

                return $streamId == 0 ? $stream['flvHD'] : $stream['flv'];
            }
        } else if ($protocol === 'rtmp') {
            $queryResult = $this->getLeChangeSdk()->queryDeviceRtmpLive($code, (int)$channelId);
            if (!empty($queryResult['data'])) {
                $stream = $queryResult['data'];

                return $streamId == 0 ? $stream['rtmpHD'] : $stream['rtmp'];
            }

            $result = $this->getLeChangeSdk()->createDeviceRtmpLive($code, (int)$channelId, [
                'streamId' => $streamId
            ]);
            if (!empty($result['data'])) {
                $stream = $result['data'];

                return $streamId == 0 ? $stream['rtmpHD'] : $stream['rtmp'];
            }
        }
    }

    /**
     * 根据streamId 查找对应的https流信息
     * @param array $streams
     * @param int $streamId
     * @return mixed|null
     */
    protected function findStreamByStreamId(array $streams, int $streamId, $key = 'hls')
    {
        $value = null;
        foreach ($streams as $stream) {
            if ($key === 'hls') {
                if ($stream['streamId'] == $streamId && false !== strpos($stream[$key], 'https')) {
                    $value = $stream;
                    break;
                }
            } else {
                $value = $stream;
                break;
            }
        }

        return $value;
    }

    public function getCamera($code)
    {
        // strlen($code) > 15 需要拆分 前15 位为设备ID，后面的为通道ID
        $codeLen = strlen($code);
        if ($codeLen > 15) {
            $deviceId = substr($code, 0, 15);
            $channelId = substr($code, 15);
        } else {
            $deviceId = $code;
            $channelId = null;
        }

        $device = $this->getLeChangeSdk()->deviceInfo($deviceId, $channelId);
        if (!empty($device)) {
            $channels = $this->mapChannels($device);
            if ($device['channelNum'] > 1) {
                $device['channelList'] = $channels;
                return $device;
            }

            return $channels[0] ?? null;
        }

        return null;
    }

    /**
     *
     * @param $device
     * @return array
     */
    protected function mapChannels($device): array
    {
        $channels = [];
        foreach ($device['channelList'] as $channel) {
            $lastOffLineTime = DateTime::createFromFormat('Ymd\THis\Z', $channel['lastOffLineTime'], new DateTimeZone('UTC'));
            $channels[] = [
                'code' => $channel['channelId'],
                'name' => $channel['channelName'],
                'deviceId' => $device['deviceId'],
                'deviceName' => $device['deviceName'],
                'deviceStatus' => $device['deviceStatus'],
                'deviceModel' => $device['deviceModel'],
                'deviceCatalog' => $device['catalog'],
                'deviceChannelNum' => $device['channelNum'],
                'channelNo' => $channel['channelId'],
                'channelName' => $channel['channelName'],
                'channelId' => $device['deviceId'] . $channel['channelId'],
                'channelStatus' => $channel['channelStatus'],
                'lastOffLineTime' => $lastOffLineTime->getTimestamp(),
                'picUrl' => $channel['channelPicUrl'],
            ];
        }

        return $channels;
    }

    public function getVideoRecorder($code)
    {
        // TODO: Implement getVideoRecorder() method.
    }

    public function devicePtzStart(string $code, $options)
    {
        $direction = $this->gbPtzDirectionToLeChangeDire($options['ptzCommandType']);
        $result = $this->getLeChangeSdk()->controlMovePTZ($code, $options['channelNo'], $direction, $options['speed'] ?? 1);

        return $result['code'] === 0;
    }

    public function devicePtzStp(string $code, $options)
    {
        // TODO: Implement devicePtzStp() method.
    }

    public function stopLive($code, array $options = [])
    {
        $channelId = ($options['channelId'] ?? 0);
        $protocol = $options['protocol'] ?? 'hls';
        if ($protocol === 'hls') {
            $queryResult = $this->getLeChangeSdk()->getLiveStreamInfo($code, (int)$channelId);
            $liveToken = $queryResult['data']['liveToken'] ?? null;
            if (!empty($liveToken)) {
                $result = $this->getLeChangeSdk()->unbindDeviceLive($liveToken);
                if ($result['code'] == 0) {
                    return true;
                }
                return false;
            }
        } else if ($protocol === 'flv') {
            $queryResult = $this->getLeChangeSdk()->queryDeviceFlvLive($code, (int)$channelId);
            if (!empty($queryResult['data'])) {
                $result = $this->getLeChangeSdk()->deleteDeviceFlvLive($code, (int)$channelId);
                if ($result['code'] == 0) {
                    return true;
                }
                return false;
            }

            return false;
        } else if ($protocol === 'rtmp') {
            $queryResult = $this->getLeChangeSdk()->queryDeviceRtmpLive($code, (int)$channelId);
            if (!empty($queryResult['data'])) {
                $result = $this->getLeChangeSdk()->deleteDeviceRtmpLive($code, (int)$channelId);
                if ($result['code'] == 0) {
                    return true;
                }
                return false;
            }

            return false;
        }
    }

    public function getVideoCover($code, $otherParam = null)
    {
        $channelNo = $otherParam['channelNo'] ?? 0;
        $result = $this->getLeChangeSdk()->setDeviceSnapEnhanced($code, (int)$channelNo);

        return $result['data']['url'] ?? null;
    }

    public function getAccessToken(array $options = [])
    {
        if (empty($options['code']) || !isset($options['channelNo'])) {
            throw new \InvalidArgumentException("缺少必要参数");
        }

        return $this->getLeChangeSdk()->getKitToken($options['code'], $options['channelNo']);
    }

    protected function gbPtzDirectionToLeChangeDire($gbDire): int
    {
        //gb 指令：1=上;2=左上;3=右上;4=下;5=左下;6=右下;7=左;8=右;9=聚焦+;10=聚焦-;11=变倍+;12=变倍-;13=光圈开;14=光圈关;
        //乐橙指令：0-上，1-下，2-左，3-右，4-左上，5-左下，6-右上，7-右下，8-放大，9-缩小，10-停止
        $items = [
            1 => 0,  // 上 -> 上
            2 => 4,  // 左上 -> 左上
            3 => 6,  // 右上 -> 右上
            4 => 1,  // 下 -> 下
            5 => 5,  // 左下 -> 左下
            6 => 7,  // 右下 -> 右下
            7 => 2,  // 左 -> 左
            8 => 3,  // 右 -> 右
            9 => 11, // 聚焦+ -> 未定义（暂定为 11）
            10 => 10,// 聚焦- -> 停止（暂定为 10）
            11 => 8, // 变倍+ -> 放大
            12 => 9, // 变倍- -> 缩小
            //13 => null, // 光圈开 -> 无对应项
            //14 => null, // 光圈关 -> 无对应项
        ];

        return $items[$gbDire] ?? $gbDire;
    }

    /**
     * @return LeChangeSdk
     */
    protected function getLeChangeSdk(): LeChangeSdk
    {
        $params = $this->currentThirdParty['params'] ?? [];
        if ($this->currentThirdParty['live_providers'] === Constants::LIVE_PROVIDER_OPEN_LE_YS7_CHANGE) {
            $params = $params['mixConfig']['leChange'] ?? [];
        }

        $call = $this->CoreW->offsetGet('sip.le_change_sdk');

        return $call($params, true);
    }
}