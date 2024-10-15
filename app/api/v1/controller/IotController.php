<?php

namespace app\api\v1\controller;

use app\api\BaseController;
use CoreW\Business\VIP\Service\VIPService;
use CoreW\Sdk\Iot\IotDriverFactory;
use support\Request;

class IotController extends BaseController
{
    public function getDeviceCatalogs(Request $request)
    {
        $items = $this->iotDriver()->deviceCatalogs();

        return json($items);
    }

    public function getDeviceList(Request $request)
    {
        $items = $this->iotDriver()->deviceList([
            'cid' => $request->get('cid', ''),
            'keyword' => $request->get('keyword', ''),
            'page' => $request->get('page', 1),
            'page_size' => $request->get('page_size', 100),
        ]);

        return json($items);
    }

    public function getDeviceInfo(Request $request, $deviceCode)
    {
        $item = $this->iotDriver()->deviceInfo($deviceCode);

        return json($item);
    }

    public function getDeviceRealData(Request $request, $deviceCode)
    {
        $item = $this->iotDriver()->deviceRealData($deviceCode);

        return json($item);
    }

    public function getDeviceHistoryData(Request $request, $deviceCode)
    {
        $item = $this->iotDriver()->deviceHistoryData($deviceCode, [
            'start_time' => $request->get('start_time', ''),
            'end_time' => $request->get('end_time', ''),
            'channel_num' => $request->get('channel_num', 1),
            'format' => 'chart',
            'type' => 9
        ]);

        return json($item);
    }

    public function getCameraLiveUrl(Request $request, $deviceCode)
    {
        $item = $this->iotDriver()->cameraLiveUrl($deviceCode);

        return json($item);
    }

    public function getGisTilesUrl(Request $request)
    {
        $item = $this->iotDriver()->gisTilesUrl();

        return json($item);
    }


    protected function iotDriver(): \CoreW\Sdk\Iot\Driver\IotInterface
    {
       $iotConfig =  $this->getVIPService()->getCompanyIotConfigByUserId($this->getUserId());

       return IotDriverFactory::create($iotConfig['serviceType'], $iotConfig);
    }

    /**
     * @return VIPService
     */
    protected function getVIPService()
    {
        return $this->createService('VIP:VIPService');
    }
}