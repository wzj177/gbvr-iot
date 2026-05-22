<?php

namespace CoreW\Sdk\Iot\Driver;

interface IotInterface
{
    public function deviceCatalogs(array $params = []);

    public function deviceList(array $params = []);

    /**
     * 设备详情
     * 此接口必须返回以下字段：device_name 设备名称 device_image 设备图片 device_code 设备编号 device_status 设备状态 device_status_text 设备状态文字 device_type 设备类型 lasted_data_time 最后数据更新时间
     * @param string|null $deviceCode
     * @param array $params
     * @return mixed
     */
    public function deviceInfo(?string $deviceCode, array $params = []);

    public function deviceRealData(?string $deviceCode, array $params = []);

    public function deviceHistoryData(?string $deviceCode, array $params = []);


    public function cameraLiveUrl(?string $deviceCode, array $params = []);

    public function gisTilesUrl(array $params = []);


    public function auth(string $token) : ?array;

}