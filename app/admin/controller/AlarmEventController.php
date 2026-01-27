<?php

namespace app\admin\controller;

use app\api\BaseController;
use CoreW\Business\Alarm\Service\AlarmEventService;
use support\Request;
use support\Response;

class AlarmEventController extends BaseController
{
    /**
     * 查询报警事件列表
     * GET /api/v2/alarm/events
     */
    public function index(Request $request): Response
    {
        $conditions = $request->get();
        $start = (int)$request->get('start', 0);
        $limit = (int)$request->get('limit', 20);

        try {
            $list = $this->getAlarmEventService()->searchAlarmEvents($conditions, [], $start, $limit);
            $total = $this->getAlarmEventService()->countAlarmEvents($conditions);

            return $this->createSuccessJsonResponse([
                'list' => $list,
                'total' => $total,
            ]);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    /**
     * 查询单个报警事件详情
     * GET /api/v2/alarm/events/:id
     */
    public function show(Request $request, $id): Response
    {
        $id = (int)$id;

        try {
            $event = $this->getAlarmEventService()->getAlarmEvent($id);

            if (!$event) {
                return $this->createErrorJsonResponse('报警事件不存在', 404);
            }

            return $this->createSuccessJsonResponse($event);
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse($e->getMessage(), 500);
        }
    }

    private function getAlarmEventService(): AlarmEventService
    {
        return $this->createService('Alarm:AlarmEventService');
    }
}
