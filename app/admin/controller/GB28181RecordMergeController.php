<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\RecordMerge\Exception\RecordMergeException;
use CoreW\Business\RecordMerge\Service\RecordMergeTaskService;
use support\Request;
use support\Response;
use support\utils\Paginator;

/**
 * GB28181 录像合并任务控制器 - 管理后台
 */
class GB28181RecordMergeController extends BaseController
{
    /**
     * 合并任务列表
     * GET /api/admin/gb28181/record-merge-tasks
     */
    public function index(Request $request) : Response
    {
        $conditions = [];

        if ($request->get('device_id')) {
            $conditions['device_id'] = $request->get('device_id');
        }
        if ($request->get('channel_id')) {
            $conditions['channel_id'] = $request->get('channel_id');
        }
        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }

        $total = $this->getRecordMergeTaskService()->countMergeTasks($conditions);
        [$offset, $limit] = $this->getOffsetAndLimit($request);

        $tasks = $this->getRecordMergeTaskService()->searchMergeTasks(
            $conditions,
            ['id' => 'DESC'],
            $offset,
            $limit
        );

        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list'      => $tasks,
            'paginator' => Paginator::toArray($paginator),
        ]);
    }

    /**
     * 合并任务详情
     * GET /api/admin/gb28181/record-merge-tasks/{id}
     */
    public function show(Request $request, $id) : Response
    {
        $task = $this->getRecordMergeTaskService()->getMergeTask((int)$id);
        if (!$task) {
            return $this->createErrorJsonResponse('合并任务不存在', null, -1, 404);
        }

        return $this->createSuccessJsonResponse($task);
    }

    /**
     * 创建合并任务
     * POST /api/admin/gb28181/record-merge-tasks
     */
    public function store(Request $request) : Response
    {
        try {
            $deviceId = $request->post('device_id', '');
            $channelId = $request->post('channel_id', '');
            $startTime = (int)$request->post('start_time', 0);
            $endTime = (int)$request->post('end_time', 0);

            if (empty($deviceId) || empty($channelId)) {
                return $this->createErrorJsonResponse('device_id和channel_id不能为空', null, -1, 400);
            }
            if ($startTime <= 0 || $endTime <= 0) {
                return $this->createErrorJsonResponse('start_time和end_time不能为空', null, -1, 400);
            }

            $task = $this->getRecordMergeTaskService()->createMergeTask($deviceId, $channelId, $startTime, $endTime);

            return $this->createSuccessJsonResponse($task, '合并任务已创建', 201);
        } catch (RecordMergeException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('创建失败：' . $e->getMessage(), null, -1, 500);
        }
    }

    /**
     * 取消合并任务（仅 pending 状态）
     * POST /api/admin/gb28181/record-merge-tasks/{id}/cancel
     */
    public function cancel(Request $request, $id) : Response
    {
        try {
            $this->getRecordMergeTaskService()->cancelMergeTask((int)$id);
            return $this->createSuccessJsonResponse(null, '已取消');
        } catch (RecordMergeException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        }
    }

    /**
     * 删除合并任务（仅 done/failed 状态）
     * DELETE /api/admin/gb28181/record-merge-tasks/{id}
     */
    public function destroy(Request $request, $id) : Response
    {
        try {
            $this->getRecordMergeTaskService()->deleteMergeTask((int)$id);
            return $this->createSuccessJsonResponse(null, '删除成功');
        } catch (RecordMergeException $e) {
            return $this->createErrorJsonResponse($e->getMessage(), null, $e->getCode(), 400);
        }
    }

    protected function getRecordMergeTaskService() : RecordMergeTaskService
    {
        return $this->createService('RecordMerge:RecordMergeTaskService');
    }
}
