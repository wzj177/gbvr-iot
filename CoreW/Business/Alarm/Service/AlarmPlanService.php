<?php

namespace CoreW\Business\Alarm\Service;

interface AlarmPlanService
{
    /**
     * 获取计划列表
     */
    public function searchPlans(array $conditions, int $start = 0, int $limit = 20) : array;

    /**
     * 统计计划数量
     */
    public function countPlans(array $conditions) : int;

    /**
     * 获取计划详情
     */
    public function getPlan(int $id) : ?array;

    /**
     * 创建计划
     */
    public function createPlan(array $data) : array;

    /**
     * 更新计划
     */
    public function updatePlan(int $id, array $data) : array;

    /**
     * 删除计划
     */
    public function deletePlan(int $id) : bool;

    /**
     * 绑定通道
     */
    public function bindChannels(int $planId, string $deviceId, array $channelIds) : bool;

    /**
     * 解绑通道
     */
    public function unbindChannel(int $planId, string $channelId) : bool;

    /**
     * 获取计划绑定的通道列表
     */
    public function getBoundChannels(int $planId) : array;

    /**
     * 匹配报警事件是否符合预案
     */
    public function matchPlan(string $deviceId, string $channelId, int $level, int $method, ?int $type = null, ?int $eventtype = null) : ?array;
}
