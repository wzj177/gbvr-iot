<?php

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\filters\AlarmPlanFilter;
use CoreW\Business\Alarm\Service\AlarmPlanService;
use support\Request;
use support\utils\Paginator;

/**
 * 报警计划管理控制器
 */
class AlarmPlanController extends BaseController
{
    /**
     * 获取报警计划列表
     */
    public function index(Request $request)
    {
        $conditions = [];

        // 状态筛选
        if ($request->get('status') !== null) {
            $conditions['status'] = $request->get('status');
        }

        // 名称搜索
        if ($request->get('name')) {
            $conditions['nameLike'] = '%' . $request->get('name') . '%';
        }

        $total = $this->getAlarmPlanService()->countPlans($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);

        $list = $this->getAlarmPlanService()->searchPlans($conditions, $offset, $limit);
        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list' => AlarmPlanFilter::publicList($list),
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    /**
     * 获取报警计划详情
     */
    public function show(Request $request, $id)
    {
        $plan = $this->getAlarmPlanService()->getPlan((int) $id);

        if (!$plan) {
            return $this->createErrorJsonResponse('报警计划不存在', null, 404);
        }

        // 获取绑定的通道列表
        $plan['bound_channels'] = $this->getAlarmPlanService()->getBoundChannels((int) $id);

        return $this->createSuccessJsonResponse($plan);
    }

    /**
     * 创建报警计划
     */
    public function store(Request $request)
    {
        $data = $request->post();

        try {
            $plan = $this->getAlarmPlanService()->createPlan($data);
            return $this->createSuccessJsonResponse($plan, '创建成功', 201);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 更新报警计划
     */
    public function update(Request $request, $id)
    {
        $data = $request->post();

        try {
            $plan = $this->getAlarmPlanService()->updatePlan((int) $id, $data);
            return $this->createSuccessJsonResponse($plan, '更新成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 删除报警计划
     */
    public function destroy(Request $request, $id)
    {
        try {
            $result = $this->getAlarmPlanService()->deletePlan((int) $id);
            if (!$result) {
                return $this->createErrorJsonResponse('报警计划不存在', null, 404);
            }
            return $this->createSuccessJsonResponse(null, '删除成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 绑定通道
     */
    public function bindChannels(Request $request, $id)
    {
        $deviceId = $request->post('device_id');
        $channelIds = $request->post('channel_ids', []);

        if (!$deviceId) {
            return $this->createErrorJsonResponse('设备ID不能为空');
        }

        if (empty($channelIds)) {
            return $this->createErrorJsonResponse('通道ID列表不能为空');
        }

        try {
            $this->getAlarmPlanService()->bindChannels((int) $id, $deviceId, $channelIds);
            return $this->createSuccessJsonResponse(null, '绑定成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 解绑通道
     */
    public function unbindChannel(Request $request, $id, $channelId)
    {
        try {
            $result = $this->getAlarmPlanService()->unbindChannel((int) $id, $channelId);
            if (!$result) {
                return $this->createErrorJsonResponse('解绑失败', null, 404);
            }
            return $this->createSuccessJsonResponse(null, '解绑成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 获取绑定的通道列表
     */
    public function boundChannels(Request $request, $id)
    {
        try {
            $channels = $this->getAlarmPlanService()->getBoundChannels((int) $id);
            return $this->createSuccessJsonResponse($channels);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * @return AlarmPlanService
     */
    private function getAlarmPlanService(): AlarmPlanService
    {
        return $this->createService('Alarm:AlarmPlanService');
    }
}
