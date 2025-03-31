<?php

namespace app\api\v1\controller;

use app\api\BaseController;
use app\api\filters\VIPFilter;
use CoreW\Business\BizEnum;
use CoreW\Business\Product\Service\ProductService;
use CoreW\Business\VIP\Dao\LoginFormDto;
use CoreW\Business\VIP\Service\VIPService;
use CoreW\Sdk\Iot\IotDriverFactory;
use support\Request;

class IotController extends BaseController
{
    protected ?string $productCode;

    public function __construct()
    {
        $this->productCode = \request()->header('X-Product-Code', \request()->get('prod_code'));
    }

    public function auth(Request $request)
    {
        $iotToken = $request->post('token', '');
        $appId = $request->post('app', '');
        if (empty($iotToken) || empty($appId)) {
            return $this->createErrorJsonResponse('参数错误');
        }

        $iotDriver = $this->getIotDriverByAppId($appId);
        if (empty($iotDriver)) {
            return $this->createErrorJsonResponse('未找到对应的配置');
        }


        $result = $iotDriver->auth($iotToken);
        if ($result['code'] === 0 && !empty($result['data']['uuid'])) {
            // iot 授权成功后自动登录
            $iotConfig = $this->getVIPService()->getCompanyIotConfigByAppId($appId);
            $dto = new LoginFormDto();
            $dto->mode = 'silent_login';
            $dto->userId = $iotConfig['userId'];
            $dto->clientType = BizEnum::TOKEN_TYPE_VIP_PC_LOGIN;
            $dto->requestIp = $request->getRealIp();
            list($vip, $token) = $this->getVIPService()->login($dto);
            $filter = new VIPFilter();
            $filter->filter($vip);

            return $this->createSuccessJsonResponse([
                'token' => $token,
                'user' => $vip
            ], '授权成功');
        }

        return $this->createErrorJsonResponse('授权失败');
    }

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
//        $item = [
//            'code' => 0,
//            'data' => [
//                'url' => 'https://zlm.boyuntong.com/rtp/24FE7973/hls.m3u8'
//            ],
//            'message' => 'ok'
//        ];
        return json($item);
    }

    public function getGisTilesUrl(Request $request)
    {
        $item = $this->iotDriver()->gisTilesUrl();

        return json($item);
    }


    protected function iotDriver(): \CoreW\Sdk\Iot\Driver\IotInterface
    {
        if ($this->productCode) {
            $userId = 0;
            $product = $this->getProductService()->getProductByCode($this->productCode);
            if (!empty($product)) {
                $userId = $product['userId'];
            }
        } else {
            $userId = $this->getUserId();
        }

        $iotConfig = $this->getVIPService()->getCompanyIotConfigByUserId($userId);

        return IotDriverFactory::create($iotConfig['serviceType'], $iotConfig);
    }

    protected function getIotDriverByAppId(string $appId): ?\CoreW\Sdk\Iot\Driver\IotInterface
    {
        $iotConfig = $this->getVIPService()->getCompanyIotConfigByAppId($appId);
        if (empty($iotConfig)) {
            return null;
        }

        return IotDriverFactory::create($iotConfig['serviceType'], $iotConfig);
    }

    /**
     * @return VIPService
     */
    protected function getVIPService(): VIPService
    {
        return $this->createService('VIP:VIPService');
    }

    /**
     * @return ProductService
     */
    protected function getProductService(): ProductService
    {
        return $this->createService('Product:ProductService');
    }
}