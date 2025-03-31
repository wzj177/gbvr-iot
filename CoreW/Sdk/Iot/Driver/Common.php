<?php

namespace CoreW\Sdk\Iot\Driver;

use CoreW\Business\BizEnum;

class Common extends Base implements IotInterface
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
        $params['device_code'] = $deviceCode;
        return $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_DEVICE_INFO, $params);
    }

    public function deviceRealData(?string $deviceCode, array $params = [])
    {
        return $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_DEVICE_REAL_DATA, $params);
    }

    public function deviceHistoryData(?string $deviceCode, array $params = [])
    {
        return $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_DEVICE_HISTORY_DATA, $params);
    }

    public function cameraLiveUrl(?string $deviceCode, array $params = [])
    {
        return $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_CAMERA_LIVE_URL, $params);
    }

    public function gisTilesUrl(array $params = [])
    {
        return $this->getFuncData(BizEnum::VIP_COMPANY_IOT_API_GIS_TILES_URL, $params);
    }

    protected function getDeviceTypeFormat(array $device): ?string
    {
        // TODO: Implement getDeviceTypeFormat() method.
    }
}