<?php

namespace CoreW\Business\Record\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Devices\Service\DeviceService;
use CoreW\Business\Record\Dao\RecordPlanDao;
use CoreW\Business\Record\Dao\RecordPlanRangeDao;
use CoreW\Business\Record\Service\RecordPlanService;
use CoreW\Dao\DaoProxy;
use support\utils\ArrayToolkit;
use CoreW\Business\Common\CommonBizException;
class RecordPlanServiceImpl extends BaseService implements RecordPlanService
{
    private const VALID_WEEK_DAYS = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];

    public function createPlan(array $data) : array
    {
        if (!ArrayToolkit::requireds($data, ['name'])) {
            throw CommonBizException::ERROR_PARAMETER_MISSING();
        }

        $existing = $this->getRecordPlanDao()->getByName($data['name']);
        if ($existing) {
            throw CommonBizException::ERROR_PARAMETER_DUPLICATE();
        }

        $fields = ArrayToolkit::parts($data, [
            'name', 'remark', 'status', 'limit_space', 'limit_days', 'over_step_plan', 'partner_id',
        ]);

        $fields['status'] = (int)($fields['status'] ?? 0);
        $fields['limit_space'] = (int)($fields['limit_space'] ?? 0);
        $fields['limit_days'] = (int)($fields['limit_days'] ?? 0);
        $fields['over_step_plan'] = in_array($fields['over_step_plan'] ?? '', ['stop_record', 'del_file'])
            ? $fields['over_step_plan'] : 'del_file';
        $fields['partner_id'] = (int)($fields['partner_id'] ?? 0);

        $plan = $this->getRecordPlanDao()->create($fields);

        // 如果同时传入了 ranges，一并创建
        if (!empty($data['ranges']) && is_array($data['ranges'])) {
            $plan['ranges'] = $this->setTimeRanges($plan['id'], $data['ranges']);
        }

        return $plan;
    }

    public function updatePlan(int $id, array $data) : array
    {
        $plan = $this->getRecordPlanDao()->get($id);
        if (!$plan) {
            throw CommonBizException::NOTFOUND_RESOURCE();
        }

        $fields = ArrayToolkit::parts($data, [
            'name', 'remark', 'status', 'limit_space', 'limit_days', 'over_step_plan',
        ]);

        if (isset($fields['name']) && $fields['name'] !== $plan['name']) {
            $existing = $this->getRecordPlanDao()->getByName($fields['name']);
            if ($existing) {
                throw CommonBizException::ERROR_PARAMETER_DUPLICATE();
            }
        }

        if (isset($fields['status'])) {
            $fields['status'] = (int)$fields['status'];
        }
        if (isset($fields['limit_space'])) {
            $fields['limit_space'] = (int)$fields['limit_space'];
        }
        if (isset($fields['limit_days'])) {
            $fields['limit_days'] = (int)$fields['limit_days'];
        }
        if (isset($fields['over_step_plan']) && !in_array($fields['over_step_plan'], ['stop_record', 'del_file'])) {
            unset($fields['over_step_plan']);
        }

        if (!empty($fields)) {
            $this->getRecordPlanDao()->update($id, $fields);
        }

        // 如果同时传入了 ranges，一并更新
        if (isset($data['ranges']) && is_array($data['ranges'])) {
            $this->setTimeRanges($id, $data['ranges']);
        }

        return $this->getPlan($id);
    }

    public function getPlan(int $id) : ?array
    {
        $plan = $this->getRecordPlanDao()->get($id);
        if (!$plan) {
            return null;
        }

        $plan['ranges'] = $this->getTimeRanges($id);
        return $plan;
    }

    public function deletePlan(int $id) : bool
    {
        $plan = $this->getRecordPlanDao()->get($id);
        if (!$plan) {
            throw CommonBizException::NOTFOUND_RESOURCE();
        }

        // 解绑所有关联通道
        $this->getDeviceService()->clearChannelRecordPlan($id);

        // 删除时间段
        $this->getRecordPlanRangeDao()->deleteByPlanId($id);

        // 删除计划
        return $this->getRecordPlanDao()->delete($id) > 0;
    }

    public function searchPlans(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20) : array
    {
        return $this->getRecordPlanDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countPlans(array $conditions) : int
    {
        return $this->getRecordPlanDao()->count($conditions);
    }

    public function togglePlanStatus(int $id, int $status) : bool
    {
        $plan = $this->getRecordPlanDao()->get($id);
        if (!$plan) {
            throw CommonBizException::NOTFOUND_RESOURCE();
        }

        $this->getRecordPlanDao()->update($id, ['status' => $status ? 1 : 0]);
        return true;
    }

    public function setTimeRanges(int $planId, array $ranges) : array
    {
        // 全量替换：先删后增
        $this->getRecordPlanRangeDao()->deleteByPlanId($planId);

        $created = [];
        foreach ($ranges as $range) {
            if (!ArrayToolkit::requireds($range, ['week_day', 'start_time', 'end_time'])) {
                continue;
            }

            $weekDay = strtoupper($range['week_day']);
            if (!in_array($weekDay, self::VALID_WEEK_DAYS)) {
                continue;
            }

            $created[] = $this->getRecordPlanRangeDao()->create([
                'record_plan_id' => $planId,
                'week_day'       => $weekDay,
                'start_time'     => $range['start_time'],
                'end_time'       => $range['end_time'],
            ]);
        }

        return $created;
    }

    public function getTimeRanges(int $planId) : array
    {
        return $this->getRecordPlanRangeDao()->findByPlanId($planId);
    }

    public function bindChannels(int $planId, array $channelIds) : int
    {
        $plan = $this->getRecordPlanDao()->get($planId);
        if (!$plan) {
            throw CommonBizException::NOTFOUND_RESOURCE();
        }

        $count = 0;
        foreach ($channelIds as $channelId) {
            $channelId = (int)$channelId;
            if ($channelId <= 0) {
                continue;
            }
            $this->getDeviceService()->updateChannel($channelId, [
                'record_plan_id' => $planId,
            ]);
            $count++;
        }

        return $count;
    }

    public function unbindChannel(int $channelId) : bool
    {
        $this->getDeviceService()->updateChannel($channelId, [
            'record_plan_id' => 0,
            'record_status'  => 0,
        ]);
        return true;
    }

    public function unbindChannels(array $channelIds) : int
    {
        $count = 0;
        foreach ($channelIds as $channelId) {
            $channelId = (int)$channelId;
            if ($channelId <= 0) {
                continue;
            }
            $this->getDeviceService()->updateChannel($channelId, [
                'record_plan_id' => 0,
                'record_status'  => 0,
            ]);
            $count++;
        }
        return $count;
    }

    public function getBoundChannels(int $planId) : array
    {
        return $this->getDeviceService()->searchChannels(
            ['record_plan_id' => $planId],
            [],
            0,
            PHP_INT_MAX
        );
    }

    public function getPlansWithRanges() : array
    {
        $plans = $this->getRecordPlanDao()->search([], [], 0, PHP_INT_MAX);
        foreach ($plans as &$plan) {
            $plan['time_range_list'] = $this->getRecordPlanRangeDao()->findByPlanId($plan['id']);
        }
        return $plans;
    }

    // ==================== DAO / Service Getters ====================

    protected function getRecordPlanDao() : RecordPlanDao|DaoProxy
    {
        return $this->createDao('Record:RecordPlanDao');
    }

    protected function getRecordPlanRangeDao() : RecordPlanRangeDao|DaoProxy
    {
        return $this->createDao('Record:RecordPlanRangeDao');
    }

    protected function getDeviceService() : DeviceService
    {
        return $this->createService('Devices:DeviceService');
    }
}
