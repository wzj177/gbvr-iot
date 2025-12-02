<?php

namespace CoreW\LiveProvider\Strategy\Impl;

use CoreW\LiveProvider\Strategy\LiveProvider;
use CoreW\LiveProvider\Strategy\LiveProviderStrategy;

class YS7LeChangeStrategy extends LiveProviderStrategy implements LiveProvider
{
    public function searchRecorders($offset, $limit, array $conditions = [], $sort = null, $columns = [])
    {
        return $this->getYs7LiveStrategy()->searchRecorders($offset, $limit, $conditions, $sort, $columns);
    }

    public function searchCameras($offset, $limit, array $conditions = [], $sort = null, $columns = []): array
    {
        $total = 0;
        try {
            $ys7Cameras = $this->getYs7LiveStrategy()->searchCameras($offset - 1, $limit, $conditions, $sort, $columns);
            $total += $ys7Cameras['total'];
        } catch (\Exception $e) {
            $ys7Cameras = [];
        }

        try {
            $leChangeCameras = $this->getLeChangeStrategy()->searchCameras($offset, $limit, $conditions, $sort, $columns);
            $total += $leChangeCameras['total'];
        } catch (\Exception $e) {
            $leChangeCameras = [];
        }

        return [
            'total' => $total,
            'ys7' => $ys7Cameras['items'] ?? [],
            'leChange' => $leChangeCameras['items'] ?? [],
        ];
    }

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

    public function getLiveUrl($code, array $options = [])
    {
        $platform = $options['platform'] ?? null;
        if (!$platform) {
            try {
                $result =  $this->getYs7LiveStrategy()->getLiveUrl($code, $options);
                if (!$result) {
                    throw new \Exception('ys7 camera not found');
                }
                return $result;
            } catch (\Exception $e) {
                return $this->getLeChangeStrategy()->getLiveUrl($code, $options);
            }
        }

        if ($platform === 'ys7') {
            return $this->getYs7LiveStrategy()->getLiveUrl($code, $options);
        }

        if ($platform === 'leChange') {
            return $this->getLeChangeStrategy()->getLiveUrl($code, $options);
        }


        return null;
    }

    public function getCamera($code): array
    {
        $ys7Camera = $this->getYs7LiveStrategy()->getCamera($code);
        if (!empty($ys7Camera)) {
            return ['ys7' => $ys7Camera];
        }

        return [
            'leChange' => $this->getLeChangeStrategy()->getCamera($code),
        ];
    }

    public function getVideoRecorder($code)
    {
        return $this->getYs7LiveStrategy()->getVideoRecorder($code);
    }

    public function getVideoCover($code, $otherParam = null)
    {
        if (($otherParam['platform'] ?? 'ys7') === 'ys7') {
            return $this->getYs7LiveStrategy()->getVideoCover($code, $otherParam);
        }

        return $this->getLeChangeStrategy()->getVideoCover($code, $otherParam);
    }

    public function devicePtzStart(string $code, $options)
    {
        if (($options['platform'] ?? 'ys7') === 'ys7') {
            return $this->getYs7LiveStrategy()->devicePtzStart($code, $options);
        }

        return $this->getLeChangeStrategy()->devicePtzStart($code, $options);
    }

    public function devicePtzStp(string $code, $options)
    {
    }

    public function stopLive($code, array $options = [])
    {
        if (($options['platform'] ?? 'ys7') === 'ys7') {
            return $this->getYs7LiveStrategy()->stopLive($code, $options);
        }

        return $this->getLeChangeStrategy()->stopLive($code, $options);
    }

    public function getAccessToken(array $options = [])
    {
        if (($options['platform'] ?? 'ys7') === 'ys7') {
            return $this->getYs7LiveStrategy()->getAccessToken($options);
        }

        return $this->getLeChangeStrategy()->getAccessToken($options);
    }

    /**
     * @return Ys7Strategy
     */
    protected function getYs7LiveStrategy(): Ys7Strategy
    {
        return $this->CoreW->offsetGet('live_provider.Ys7')->setCurrentThirdParty($this->currentThirdParty);
    }

    /**
     * @return LeChangeStrategy
     */
    public function getLeChangeStrategy(): LeChangeStrategy
    {
        return $this->CoreW->offsetGet('live_provider.LeChange')->setCurrentThirdParty($this->currentThirdParty);
    }
}