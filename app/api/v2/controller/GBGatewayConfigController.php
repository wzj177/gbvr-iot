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
    public function getConfig(Request $request) : Response
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
    public function heartbeat(Request $request) : Response
    {
        $gatewayId = $request->post('gateway_id', '');
        if (empty($gatewayId)) {
            return $this->createErrorJsonResponse('gateway_id参数缺失', null, -1, 400);
        }

        $info = [
            'pid'          => $request->post('pid'),
            'ip'           => $request->post('ip'),
            'device_count' => $request->post('device_count', 0),
        ];

        $result = $this->getSipGatewayService()->updateHeartbeat($gatewayId, $info);

        if (!$result) {
            return $this->createErrorJsonResponse('网关不存在', null, -1, 404);
        }

        return $this->createSuccessJsonResponse();
    }

    /**
     * Gateway 自动注册
     * POST /api/v2/gb/gateway/register
     */
    public function register(Request $request) : Response
    {
        $gatewayId = $request->post('gateway_id', '');
        if (empty($gatewayId)) {
            return $this->createErrorJsonResponse('gateway_id参数缺失', null, -1, 400);
        }

        $post = $request->post();
        $data = [
            'gateway_id'               => $gatewayId,
            'gateway_name'             => $post['gateway_name'] ?? '',
            'server_id'                => $post['server_id'] ?? '',
            'server_domain'            => $post['server_domain'] ?? '',
            'sip_host'                 => $post['sip_host'] ?? '',
            'sip_port'                 => $post['sip_port'] ?? 0,
            'transport'                => $post['transport'] ?? '',
            'public_ip'                => $post['public_ip'] ?? '',
            'device_password'          => $post['device_password'] ?? '',
            'authentication'           => $post['authentication'] ?? true,
            'sip_username'             => $post['sip_username'] ?? '',
            'register_expires'         => $post['register_expires'] ?? 3600,
            'keepalive_interval'       => $post['keepalive_interval'] ?? 60,
            'heartbeat_timeout'        => $post['heartbeat_timeout'] ?? 180,
            'keepalive_lost_number'    => $post['keepalive_lost_number'] ?? 3,
            'catalog_auto_query'       => $post['catalog_auto_query'] ?? true,
            'encoding_type'            => $post['encoding_type'] ?? 'GB2312',
            'task_worker_num'          => $post['task_worker_num'] ?? 4,
            'timer_interval'           => $post['timer_interval'] ?? 60,
            'max_devices'              => $post['max_devices'] ?? 10000,
            'broadcast_push_after_ack' => $post['broadcast_push_after_ack'] ?? true,
            'mq_type'                  => $post['mq_type'] ?? 'redis',
            'mq_config'                => $post['mq_config'] ?? [],
            'redis_config'             => $post['redis_config'] ?? [],
            'api_config'               => $post['api_config'] ?? [],
            'log_level'                => $post['log_level'] ?? 'INFO',
            'debug'                    => $post['debug'] ? intval($post['debug']) : 0,
            'pid'                      => $post['pid'] ?? null,
            'ip'                       => $post['ip'] ?? null,
        ];

        $gateway = $this->getSipGatewayService()->registerGateway($data);
        return $this->createSuccessJsonResponse($gateway);
    }

    protected function getSipGatewayService() : SipGatewayService
    {
        return $this->createService('SipGateway:SipGatewayService');
    }
}
