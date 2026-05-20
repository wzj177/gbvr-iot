<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Record\Service\RecordPlanService;
use CoreW\Business\RecordFile\Service\RecordFileService;
use support\Request;
use support\utils\Paginator;

/**
 * GB28181 录像计划控制器 - 管理后台
 */
class GB28181RecordPlanController extends BaseController
{
    /**
     * 录像计划列表
     * GET /api/admin/gb28181/record-plans
     */
    public function index(Request $request)
    {
        $conditions = [];

        if ($request->get('name')) {
            $conditions['nameLike'] = '%' . $request->get('name') . '%';
        }
        if ($request->get('status') !== null && $request->get('status') !== '') {
            $conditions['status'] = (int)$request->get('status');
        }

        $total = $this->getRecordPlanService()->countPlans($conditions);
        [$offset, $limit] = $this->getOffsetAndLimit($request);

        $plans = $this->getRecordPlanService()->searchPlans($conditions, ['id' => 'DESC'], $offset, $limit);

        // 附加每个计划绑定的通道数
        foreach ($plans as &$plan) {
            $plan['channel_count'] = count($this->getRecordPlanService()->getBoundChannels($plan['id']));
        }

        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list'      => $plans,
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    /**
     * 录像计划详情（含时间段 + 绑定通道）
     * GET /api/admin/gb28181/record-plans/{id}
     */
    public function show(Request $request, $id)
    {
        $plan = $this->getRecordPlanService()->getPlan((int)$id);
        if (!$plan) {
            return $this->createErrorJsonResponse('录像计划不存在', null, -1, 404);
        }

        $plan['channels'] = $this->getRecordPlanService()->getBoundChannels($plan['id']);

        return $this->createSuccessJsonResponse($plan);
    }

    /**
     * 创建录像计划
     * POST /api/admin/gb28181/record-plans
     */
    public function store(Request $request)
    {
        $data = $request->post();

        try {
            $plan = $this->getRecordPlanService()->createPlan($data);
            return $this->createSuccessJsonResponse($plan, '创建成功', 201);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 更新录像计划
     * PUT /api/admin/gb28181/record-plans/{id}
     */
    public function update(Request $request, $id)
    {
        $data = $request->post();

        try {
            $plan = $this->getRecordPlanService()->updatePlan((int)$id, $data);
            return $this->createSuccessJsonResponse($plan, '更新成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 删除录像计划
     * DELETE /api/admin/gb28181/record-plans/{id}
     */
    public function destroy(Request $request, $id)
    {
        try {
            $this->getRecordPlanService()->deletePlan((int)$id);
            return $this->createSuccessJsonResponse(null, '删除成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 启用/禁用录像计划
     * POST /api/admin/gb28181/record-plans/{id}/toggle
     */
    public function toggle(Request $request, $id)
    {
        $status = (int)$request->post('status', 0);

        try {
            $this->getRecordPlanService()->togglePlanStatus((int)$id, $status);
            return $this->createSuccessJsonResponse(null, $status ? '已启用' : '已禁用');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 设置时间段（全量替换）
     * POST /api/admin/gb28181/record-plans/{id}/ranges
     */
    public function setRanges(Request $request, $id)
    {
        $ranges = $request->post('ranges', []);

        if (!is_array($ranges)) {
            return $this->createErrorJsonResponse('ranges 必须是数组');
        }

        try {
            $result = $this->getRecordPlanService()->setTimeRanges((int)$id, $ranges);
            return $this->createSuccessJsonResponse($result, '时间段设置成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 绑定通道到录像计划
     * POST /api/admin/gb28181/record-plans/{id}/channels
     */
    public function bindChannels(Request $request, $id)
    {
        $channelIds = $request->post('channel_ids', []);

        if (empty($channelIds) || !is_array($channelIds)) {
            return $this->createErrorJsonResponse('channel_ids 必须是非空数组');
        }

        try {
            $count = $this->getRecordPlanService()->bindChannels((int)$id, $channelIds);
            return $this->createSuccessJsonResponse(['count' => $count], "已绑定 {$count} 个通道");
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 解绑通道的录像计划（单个）
     * DELETE /api/admin/gb28181/record-plans/channels/{channelId}
     */
    public function unbindChannel(Request $request, $channelId)
    {
        try {
            $this->getRecordPlanService()->unbindChannel((int)$channelId);
            return $this->createSuccessJsonResponse(null, '解绑成功');
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 批量解绑通道的录像计划
     * POST /api/admin/gb28181/record-plans/channels/unbind
     */
    public function unbindChannels(Request $request)
    {
        $channelIds = $request->post('channel_ids', []);

        if (empty($channelIds) || !is_array($channelIds)) {
            return $this->createErrorJsonResponse('channel_ids 必须是非空数组');
        }

        try {
            $count = $this->getRecordPlanService()->unbindChannels($channelIds);
            return $this->createSuccessJsonResponse(['count' => $count], "已解绑 {$count} 个通道");
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * 获取录像计划绑定的通道
     * GET /api/admin/gb28181/record-plans/{id}/channels
     */
    public function boundChannels(Request $request, $id)
    {
        try {
            $channels = $this->getRecordPlanService()->getBoundChannels((int)$id);
            return $this->createSuccessJsonResponse($channels);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage());
        }
    }

    /**
     * @return RecordPlanService
     */
    private function getRecordPlanService() : RecordPlanService
    {
        return $this->createService('Record:RecordPlanService');
    }
}
