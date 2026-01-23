<?php

namespace app\api\v2\controller;

use app\api\BaseController;
use CoreW\Business\Subscribe\Service\SubscribeService;
use support\Request;
use support\Response;

class GB28181SubscribeController extends BaseController
{
    /**
     * 创建/更新订阅配置
     * POST /api/v2/gb28181/subscribe/config
     */
    public function saveConfig(Request $request): Response
    {
        $deviceId = $request->post('device_id', '');
        $channelId = $request->post('channel_id', null);

        if (empty($deviceId)) {
            return $this->createErrorJsonResponse('设备ID不能为空', 400);
        }

        $config = [
            'event_catalog' => (int)$request->post('event_catalog', 0),
            'event_alarm' => (int)$request->post('event_alarm', 0),
            'event_mobile_position' => (int)$request->post('event_mobile_position', 0),
            'alarm_priority_min' => (int)$request->post('alarm_priority_min', 0),
            'alarm_priority_max' => (int)$request->post('alarm_priority_max', 4),
            'mobile_interval_sec' => (int)$request->post('mobile_interval_sec', 5),
            'subscribe_expires' => (int)$request->post('subscribe_expires', 3600),
            'auto_renew' => (int)$request->post('auto_renew', 1),
            'status' => (int)$request->post('status', 1),
        ];

        // 验证至少有一个订阅类型
        if (empty($config['event_catalog']) &&
            empty($config['event_alarm']) &&
            empty($config['event_mobile_position'])) {
            return $this->createErrorJsonResponse('请至少选择一种订阅类型', 400);
        }

        try {
            $result = $this->getSubscribeService()->saveSubscribeConfig($deviceId, $channelId, $config);
            return $this->createSuccessJsonResponse($result, '保存成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * 批量创建订阅配置
     * POST /api/v2/gb28181/subscribe/batch
     */
    public function batchCreate(Request $request): Response
    {
        $deviceIds = $request->post('device_ids', []);
        $defaultConfig = $request->post('config', []);

        if (empty($deviceIds)) {
            return $this->createErrorJsonResponse('设备ID列表不能为空', 400);
        }

        try {
            $count = $this->getSubscribeService()->batchCreateSubscribeConfigs($deviceIds, $defaultConfig);
            return $this->createSuccessJsonResponse(['count' => $count], "成功创建 {$count} 个订阅配置");
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * 取消订阅
     * POST /api/v2/gb28181/subscribe/cancel
     */
    public function cancel(Request $request): Response
    {
        $configId = (int)$request->post('config_id', 0);

        if ($configId <= 0) {
            return $this->createErrorJsonResponse('配置ID不能为空', 400);
        }

        try {
            $this->getSubscribeService()->cancelSubscribe($configId);
            return $this->createSuccessJsonResponse([], '取消成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * 查询订阅配置列表
     * GET /api/v2/gb28181/subscribe/configs
     */
    public function listConfigs(Request $request): Response
    {
        $conditions = $request->get();
        $start = (int)$request->get('start', 0);
        $limit = (int)$request->get('limit', 20);

        try {
            $list = $this->getSubscribeService()->searchSubscribeConfigs($conditions, [], $start, $limit);
            $total = $this->getSubscribeService()->countSubscribeConfigs($conditions);

            return $this->createSuccessJsonResponse([
                'list' => $list,
                'total' => $total,
            ]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * 查询单个订阅配置
     * GET /api/v2/gb28181/subscribe/config
     */
    public function getConfig(Request $request): Response
    {
        $deviceId = $request->get('device_id', '');
        $channelId = $request->get('channel_id', null);

        if (empty($deviceId)) {
            return $this->createErrorJsonResponse('设备ID不能为空', 400);
        }

        try {
            $config = $this->getSubscribeService()->getSubscribeConfig($deviceId, $channelId);

            if (!$config) {
                return $this->createErrorJsonResponse('配置不存在', 404);
            }

            return $this->createSuccessJsonResponse($config);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * 获取订阅类型选项
     * GET /api/v2/gb28181/subscribe/options
     */
    public function options(): Response
    {
        return $this->createSuccessJsonResponse([
            'event_types' => \CoreW\Business\Subscribe\Enums\SubscribeEventTypeEnum::getItems(),
            'alarm_priorities' => [
                ['key' => 0, 'value' => '0级'],
                ['key' => 1, 'value' => '1级'],
                ['key' => 2, 'value' => '2级'],
                ['key' => 3, 'value' => '3级'],
                ['key' => 4, 'value' => '4级'],
            ],
        ]);
    }

    private function getSubscribeService(): SubscribeService
    {
        return $this->createService('Subscribe:SubscribeService');
    }
}
