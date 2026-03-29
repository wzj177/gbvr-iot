<?php

namespace app\api\v2\controller;

use app\api\BaseController;
use CoreW\Business\SipGateway\Service\SipGatewayService;
use support\Log;
use support\Request;
use support\Response;

class GBGatewayConfigController extends BaseController
{
    /**
     * Gateway 启动时拉取完整配置
     * GET /api/v2/gb/gateway/config?gateway_id=xxx
     */
    public function getConfig(Request $request): Response
    {
        $gatewayId = $request->get('gateway_id', '');
        if (empty($gatewayId)) {
            return $this->createErrorJsonResponse('gateway_id参数缺失', null, -1, 400);
        }

        $config = $this->getSipGatewayService()->getGatewayFullConfig($gatewayId);
        if (!$config) {
            return $this->createErrorJsonResponse('网关不存在', null, -1, 404);
        }

        return $this->createSuccessJsonResponse($config);
    }

    /**
     * Gateway 心跳上报
     * POST /api/v2/gb/gateway/heartbeat
     */
    public function heartbeat(Request $request): Response
    {
        $gatewayId = $request->post('gateway_id', '');
        if (empty($gatewayId)) {
            return $this->createErrorJsonResponse('gateway_id参数缺失', null, -1, 400);
        }

        $info = [
            'pid' => $request->post('pid'),
            'ip' => $request->post('ip'),
            'device_count' => $request->post('device_count', 0),
        ];

        $result = $this->getSipGatewayService()->updateHeartbeat($gatewayId, $info);

        if (!$result) {
            return $this->createErrorJsonResponse('网关不存在', null, -1, 404);
        }

        return $this->createSuccessJsonResponse();
    }

    protected function getSipGatewayService(): SipGatewayService
    {
        return $this->createService('SipGateway:SipGatewayService');
    }
}
