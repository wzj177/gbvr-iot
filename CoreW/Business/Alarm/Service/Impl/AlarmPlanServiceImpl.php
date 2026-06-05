<?php

namespace CoreW\Business\Alarm\Service\Impl;

use CoreW\Business\Alarm\Dao\AlarmPlanDao;
use CoreW\Business\Alarm\Service\AlarmPlanService;
use CoreW\Business\BaseService;
use CoreW\Dao\DaoProxy;
use support\Log;
use CoreW\Business\Common\CommonBizException;

class AlarmPlanServiceImpl extends BaseService implements AlarmPlanService
{
    public function searchPlans(array $conditions, int $start = 0, int $limit = 20) : array
    {
        return $this->getAlarmPlanDao()->search($conditions, ['id' => 'DESC'], $start, $limit);
    }

    public function countPlans(array $conditions) : int
    {
        return $this->getAlarmPlanDao()->count($conditions);
    }

    public function getPlan(int $id) : ?array
    {
        return $this->getAlarmPlanDao()->get($id);
    }

    public function createPlan(array $data) : array
    {
        // 验证必填字段
        if (!isset($data['name']) || empty($data['name'])) {
            throw CommonBizException::ERROR_PARAMETER_MISSING();
        }

        // 过滤字段
        $fields = [
            'name'                  => $data['name'],
            'status'                => $data['status'] ?? 1,
            'remark'                => $data['remark'] ?? null,
            'snapshot_interval_sec' => $data['snapshot_interval_sec'] ?? 0,
            'record_duration_sec'   => $data['record_duration_sec'] ?? 0,
            'alarm_level'           => $data['alarm_level'] ?? null,
            'alarm_method'          => $data['alarm_method'] ?? null,
            'alarm_type'            => $data['alarm_type'] ?? null,
            'alarm_eventtype'       => $data['alarm_eventtype'] ?? null,
        ];

        // 验证数值范围
        if ($fields['snapshot_interval_sec'] < 0) {
            throw CommonBizException::ERROR_PARAMETER();
        }
        if ($fields['record_duration_sec'] < 0) {
            throw CommonBizException::ERROR_PARAMETER();
        }

        // 验证至少有一个报警匹配条件
        if (empty($fields['alarm_level']) && empty($fields['alarm_method'])) {
            throw CommonBizException::ERROR_PARAMETER();
        }

        return $this->getAlarmPlanDao()->create($fields);
    }

    public function updatePlan(int $id, array $data) : array
    {
        $plan = $this->getAlarmPlanDao()->get($id);
        if (!$plan) {
            throw CommonBizException::ERROR_PARAMETER_NOT_FOUND();
        }

        // 过滤可更新字段
        $fields = [
            'name'                  => $data['name'] ?? $plan['name'],
            'status'                => $data['status'] ?? $plan['status'],
            'remark'                => $data['remark'] ?? $plan['remark'],
            'snapshot_interval_sec' => $data['snapshot_interval_sec'] ?? $plan['snapshot_interval_sec'],
            'record_duration_sec'   => $data['record_duration_sec'] ?? $plan['record_duration_sec'],
            'alarm_level'           => $data['alarm_level'] ?? $plan['alarm_level'],
            'alarm_method'          => $data['alarm_method'] ?? $plan['alarm_method'],
            'alarm_type'            => $data['alarm_type'] ?? $plan['alarm_type'],
            'alarm_eventtype'       => $data['alarm_eventtype'] ?? $plan['alarm_eventtype'],
        ];

        // 验证数值范围
        if ($fields['snapshot_interval_sec'] < 0) {
            throw CommonBizException::ERROR_PARAMETER();
        }
        if ($fields['record_duration_sec'] < 0) {
            throw CommonBizException::ERROR_PARAMETER();
        }

        // 验证至少有一个报警匹配条件
        if (empty($fields['alarm_level']) && empty($fields['alarm_method'])) {
            throw CommonBizException::ERROR_PARAMETER();
        }

        $this->getAlarmPlanDao()->update($id, $fields);

        return $this->getAlarmPlanDao()->get($id);
    }

    public function deletePlan(int $id) : bool
    {
        $plan = $this->getAlarmPlanDao()->get($id);
        if (!$plan) {
            return false;
        }

        // 先删除关联的通道绑定
        $this->bfw['db']->executeStatement(
            "DELETE FROM gv_alarm_plan_channel WHERE alarm_plan_id = ?",
            [$id]
        );

        // 删除计划
        $this->getAlarmPlanDao()->delete($id);

        Log::channel('sip')->info('Alarm plan deleted', ['plan_id' => $id]);

        return true;
    }

    public function bindChannels(int $planId, string $deviceId, array $channelIds) : bool
    {
        $plan = $this->getAlarmPlanDao()->get($planId);
        if (!$plan) {
            throw CommonBizException::ERROR_PARAMETER_NOT_FOUND();
        }

        if (empty($channelIds)) {
            throw CommonBizException::ERROR_PARAMETER();
        }

        $now = date('Y-m-d H:i:s');

        foreach ($channelIds as $channelId) {
            // 检查是否已存在
            $existing = $this->bfw['db']->fetchOne(
                "SELECT * FROM gv_alarm_plan_channel WHERE alarm_plan_id = ? AND device_id = ? AND channel_id = ?",
                [$planId, $deviceId, $channelId]
            );

            if ($existing) {
                // 更新 enabled 状态
                $this->bfw['db']->executeStatement(
                    "UPDATE gv_alarm_plan_channel SET enabled = 1, updated_at = ? WHERE id = ?",
                    [$now, $existing['id']]
                );
            } else {
                // 插入新记录
                $this->bfw['db']->insert('gv_alarm_plan_channel', [
                    'alarm_plan_id' => $planId,
                    'device_id'     => $deviceId,
                    'channel_id'    => $channelId,
                    'enabled'       => 1,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
            }
        }

        Log::channel('sip')->info('Alarm plan channels bound', [
            'plan_id'     => $planId,
            'device_id'   => $deviceId,
            'channel_ids' => $channelIds,
        ]);

        return true;
    }

    public function unbindChannel(int $planId, string $channelId) : bool
    {
        $plan = $this->getAlarmPlanDao()->get($planId);
        if (!$plan) {
            return false;
        }

        $result = $this->bfw['db']->executeStatement(
            "DELETE FROM gv_alarm_plan_channel WHERE alarm_plan_id = ? AND channel_id = ?",
            [$planId, $channelId]
        );

        if ($result > 0) {
            Log::channel('sip')->info('Alarm plan channel unbound', [
                'plan_id'    => $planId,
                'channel_id' => $channelId,
            ]);
            return true;
        }

        return false;
    }

    public function getBoundChannels(int $planId) : array
    {
        $sql = "SELECT pc.*, c.channel_name
                FROM gv_alarm_plan_channel pc
                LEFT JOIN gv_device_channels c ON c.device_id = pc.device_id AND c.channel_id = pc.channel_id
                WHERE pc.alarm_plan_id = ?
                ORDER BY pc.device_id, pc.channel_id";

        return $this->bfw['db']->fetchAll($sql, [$planId]);
    }

    public function matchPlan(string $deviceId, string $channelId, int $level, int $method, ?int $type = null, ?int $eventtype = null) : ?array
    {
        // 获取该通道绑定的所有启用的预案
        $plans = $this->getAlarmPlanDao()->getPlansByDeviceAndChannel($deviceId, $channelId);

        foreach ($plans as $plan) {
            // 检查报警级别匹配
            if (!empty($plan['alarm_level'])) {
                $alarmLevels = is_array($plan['alarm_level']) ? $plan['alarm_level'] : json_decode($plan['alarm_level'], true);
                if (!in_array($level, $alarmLevels)) {
                    continue;
                }
            }

            // 检查报警方式匹配
            if (!empty($plan['alarm_method'])) {
                $alarmMethods = is_array($plan['alarm_method']) ? $plan['alarm_method'] : json_decode($plan['alarm_method'], true);
                if (!in_array($method, $alarmMethods)) {
                    continue;
                }
            }

            // 检查报警类型匹配
            if (!empty($plan['alarm_type']) && $type !== null) {
                $alarmTypes = is_array($plan['alarm_type']) ? $plan['alarm_type'] : json_decode($plan['alarm_type'], true);
                if (!in_array($type, $alarmTypes)) {
                    continue;
                }
            }

            // 检查事件类型匹配（仅在 method=5 且 type=6 时有效）
            if (!empty($plan['alarm_eventtype']) && $eventtype !== null) {
                // 只有 method=5 且 type=6 (入侵检测) 时 eventtype 才有意义
                if ($method === 5 && $type === 6) {
                    $eventTypes = is_array($plan['alarm_eventtype']) ? $plan['alarm_eventtype'] : json_decode($plan['alarm_eventtype'], true);
                    if (!in_array($eventtype, $eventTypes)) {
                        continue;
                    }
                }
            }

            // 所有条件都匹配
            return $plan;
        }

        return null;
    }

    protected function getAlarmPlanDao() : AlarmPlanDao|DaoProxy
    {
        return $this->createDao('Alarm:AlarmPlanDao');
    }
}
