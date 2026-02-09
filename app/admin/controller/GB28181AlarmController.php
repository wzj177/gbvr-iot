<?php

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\filters\AlarmEventFilter;
use CoreW\Business\Alarm\Service\AlarmEventService;
use support\Request;
use support\utils\ArrayToolkit;
use support\utils\Paginator;

/**
 * GB28181 报警事件控制器 - 管理后台
 */
class GB28181AlarmController extends BaseController
{
    /**
     * 获取报警事件列表
     * GET /admin/api/gb28181/alarms
     */
    public function index(Request $request)
    {
        $conditions = [];

        // 设备ID筛选
        if ($request->get('device_id')) {
            $conditions['device_id'] = $request->get('device_id');
        }

        // 通道ID筛选
        if ($request->get('channel_id')) {
            $conditions['channel_id'] = $request->get('channel_id');
        }

        // 报警级别筛选
        if ($request->get('level')) {
            $conditions['level'] = $request->get('level');
        }

        // 报警方式筛选
        if ($request->get('method')) {
            $conditions['method'] = $request->get('method');
        }

        // 关联预案筛选
        if ($request->get('alarm_plan_id')) {
            $conditions['alarm_plan_id'] = $request->get('alarm_plan_id');
        }

        // 时间范围筛选 - 兼容前端 start_time/end_time 参数
        if ($request->get('start_time')) {
            $conditions['start_time'] = $request->get('start_time');
        } elseif ($request->get('alarm_time_gte')) {
            $conditions['alarm_time_gte'] = $request->get('alarm_time_gte');
        }
        if ($request->get('end_time')) {
            $conditions['end_time'] = $request->get('end_time');
        } elseif ($request->get('alarm_time_lte')) {
            $conditions['alarm_time_lte'] = $request->get('alarm_time_lte');
        }

        // 快速时间筛选
        if ($request->get('quick_time')) {
            $conditions['quick_time'] = $request->get('quick_time');
        }

        $total = $this->getAlarmEventService()->countAlarmEvents($conditions);
        list($offset, $limit) = $this->getOffsetAndLimit($request);

        $list = $this->getAlarmEventService()->searchAlarmEvents($conditions, ['alarm_time' => 'DESC'], $offset, $limit);

        // 获取统计信息（可选，用于仪表盘展示）
        $summary = $this->getAlarmEventService()->getSummary();

        return $this->createSuccessJsonResponse([
            'list' => AlarmEventFilter::publicList($list),
            'total' => $total,
            'summary' => $summary,
        ]);
    }

    /**
     * 获取报警事件详情
     * GET /admin/api/gb28181/alarms/:id
     */
    public function show(Request $request, $id)
    {
        $event = $this->getAlarmEventService()->getAlarmEvent((int) $id);

        if (!$event) {
            return $this->createErrorJsonResponse('报警事件不存在', null, 404);
        }

        // 获取关联的快照和录像
        $snapshots = $this->getAlarmEventService()->getAlarmSnapshots((int) $id);
        $records = $this->getAlarmEventService()->getAlarmRecords((int) $id);

        $data = AlarmEventFilter::one($event);
        $data['assets'] = [
            'snapshots' => $snapshots,
            'records' => $records,
        ];

        return $this->createSuccessJsonResponse($data);
    }

    /**
     * 更新报警事件状态 (如: 已确认、已处理)
     * PUT /admin/api/gb28181/alarms/:id
     */
    public function update(Request $request, $id)
    {
        $data = $request->post();

        // 只保留允许更新的字段
        $fields = ArrayToolkit::parts($data, ['status', 'remark', 'handled_by', 'handled_time']);

        $event = $this->getAlarmEventService()->updateAlarmEvent((int) $id, $fields);

        return $this->createSuccessJsonResponse(AlarmEventFilter::one($event));
    }

    /**
     * @return AlarmEventService
     */
    private function getAlarmEventService(): AlarmEventService
    {
        return $this->createService('Alarm:AlarmEventService');
    }
}
