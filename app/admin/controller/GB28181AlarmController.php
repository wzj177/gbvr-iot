<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Devices\Service\AlarmService;
use support\Request;
use support\utils\Paginator;

/**
 * GB28181 报警管理控制器 - 管理后台
 */
class GB28181AlarmController extends BaseController
{
    /**
     * 获取报警列表
     */
    public function index(Request $request)
    {
        $conditions = [];

        if ($request->get('module')) {
            $conditions['module'] = $request->get('module');
        }

        if ($request->get('keyword')) {
            $conditions['keyword'] = $request->get('keyword');
        }

        if ($request->get('status')) {
            $conditions['status'] = $request->get('status');
        }

        if ($request->get('device_id')) {
            $conditions['device_id'] = $request->get('device_id');
        }

        if ($request->get('type')) {
            $conditions['type'] = $request->get('type');
        }

        if ($request->get('start_time')) {
            $conditions['start_time'] = $request->get('start_time');
        }

        if ($request->get('end_time')) {
            $conditions['end_time'] = $request->get('end_time');
        }

        // 模拟报警数据（实际项目中应从数据库获取）
        $alarms = $this->getAlarmsByConditions($conditions);

        $total = count($alarms);
        list($offset, $limit) = $this->getOffsetAndLimit($request);

        $pagedAlarms = array_slice($alarms, $offset, $limit);

        $paginator = new Paginator($offset, $total, $request->uri(), $limit);

        return $this->createSuccessJsonResponse([
            'list' => $pagedAlarms,
            'paginator' => Paginator::toArray($paginator)
        ]);
    }

    /**
     * 获取报警详情
     */
    public function show(Request $request, $id)
    {
        // 模拟获取报警详情（实际项目中应从数据库获取）
        $alarm = $this->getAlarmById($id);

        if (!$alarm) {
            return $this->createErrorJsonResponse('报警不存在', 404);
        }

        return $this->createSuccessJsonResponse($alarm);
    }

    /**
     * 处理报警
     */
    public function update(Request $request, $id)
    {
        $action = $request->put('action');
        $remark = $request->put('remark');
        $handledStatus = $request->put('handled_status');

        if (!$action) {
            return $this->createErrorJsonResponse('缺少action参数', 400);
        }

        try {
            // 更新报警状态
            $result = $this->getAlarmService()->updateAlarmStatus($id, $handledStatus, $action, $remark);

            if (!$result) {
                return $this->createErrorJsonResponse('更新报警状态失败', 500);
            }
        } catch (\Exception $e) {
            return $this->createErrorJsonResponse('更新报警状态异常: ' . $e->getMessage(), 500);
        }

        return $this->createSuccessJsonResponse([
            'message' => '报警状态已更新',
        ]);
    }

    /**
     * 模拟根据ID获取报警详情
     *
     * @param string $id
     * @return array|null
     */
    private function getAlarmById(string $id): ?array
    {
        // 模拟数据，实际项目中应从数据库获取
        $mockAlarms = [
            [
                'id' => 'alarm_001',
                'device_id' => '34020000001320000001',
                'device_name' => '摄像头1',
                'channel_id' => '001',
                'channel_name' => '主入口',
                'type' => 'motion',
                'status' => 'active',
                'description' => '检测到移动物体',
                'timestamp' => date('Y-m-d\TH:i:s', strtotime('-1 hour')),
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                'severity' => 'medium',
                'priority' => 2
            ],
            [
                'id' => 'alarm_002',
                'device_id' => '34020000001320000002',
                'device_name' => 'NVR1',
                'channel_id' => '002',
                'channel_name' => '后门',
                'type' => 'tamper',
                'status' => 'active',
                'description' => '检测到摄像头被遮挡',
                'timestamp' => date('Y-m-d\TH:i:s', strtotime('-30 minutes')),
                'created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
                'severity' => 'high',
                'priority' => 3
            ]
        ];

        foreach ($mockAlarms as $alarm) {
            if ($alarm['id'] === $id) {
                return $alarm;
            }
        }

        return null;
    }

    /**
     * 模拟根据条件获取报警数据
     *
     * @param array $conditions
     * @return array
     */
    private function getAlarmsByConditions(array $conditions): array
    {
        // 模拟数据，实际项目中应从数据库获取
        $mockAlarms = [
            [
                'id' => 'alarm_001',
                'device_id' => '34020000001320000001',
                'device_name' => '摄像头1',
                'channel_id' => '001',
                'channel_name' => '主入口',
                'type' => 'motion',
                'status' => 'active',
                'description' => '检测到移动物体',
                'timestamp' => date('Y-m-d\TH:i:s', strtotime('-1 hour')),
                'created_at' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                'severity' => 'medium',
                'priority' => 2
            ],
            [
                'id' => 'alarm_002',
                'device_id' => '34020000001320000002',
                'device_name' => 'NVR1',
                'channel_id' => '002',
                'channel_name' => '后门',
                'type' => 'tamper',
                'status' => 'active',
                'description' => '检测到摄像头被遮挡',
                'timestamp' => date('Y-m-d\TH:i:s', strtotime('-30 minutes')),
                'created_at' => date('Y-m-d H:i:s', strtotime('-30 minutes')),
                'severity' => 'high',
                'priority' => 3
            ],
            [
                'id' => 'alarm_003',
                'device_id' => '34020000001320000003',
                'device_name' => '摄像头3',
                'channel_id' => '003',
                'channel_name' => '停车场',
                'type' => 'motion',
                'status' => 'handled',
                'description' => '检测到移动物体',
                'timestamp' => date('Y-m-d\TH:i:s', strtotime('-2 hours')),
                'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'severity' => 'low',
                'priority' => 1
            ]
        ];

        // 根据条件过滤数据
        $filtered = $mockAlarms;

        if (isset($conditions['device_id'])) {
            $filtered = array_filter($filtered, function ($alarm) use ($conditions) {
                return $alarm['device_id'] === $conditions['device_id'];
            });
        }

        if (isset($conditions['type'])) {
            $filtered = array_filter($filtered, function ($alarm) use ($conditions) {
                return $alarm['type'] === $conditions['type'];
            });
        }

        if (isset($conditions['status'])) {
            $filtered = array_filter($filtered, function ($alarm) use ($conditions) {
                return $alarm['status'] === $conditions['status'];
            });
        }

        if (isset($conditions['keyword'])) {
            $keyword = strtolower($conditions['keyword']);
            $filtered = array_filter($filtered, function ($alarm) use ($keyword) {
                return strpos(strtolower($alarm['description']), $keyword) !== false ||
                    strpos(strtolower($alarm['device_name']), $keyword) !== false;
            });
        }

        return array_values($filtered);
    }

    /**
     * @return AlarmService
     */
    private function getAlarmService(): AlarmService
    {
        return $this->createService('Devices:AlarmService');
    }
}