<?php

namespace CoreW\Business\Record\Service;

interface RecordPlanService
{
    /**
     * 创建录像计划
     */
    public function createPlan(array $data): array;

    /**
     * 更新录像计划
     */
    public function updatePlan(int $id, array $data): array;

    /**
     * 获取录像计划详情（含时间段）
     */
    public function getPlan(int $id): ?array;

    /**
     * 删除录像计划
     */
    public function deletePlan(int $id): bool;

    /**
     * 搜索录像计划
     */
    public function searchPlans(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20): array;

    /**
     * 统计录像计划数量
     */
    public function countPlans(array $conditions): int;

    /**
     * 启用/禁用录像计划
     */
    public function togglePlanStatus(int $id, int $status): bool;

    /**
     * 设置录像计划的时间段（全量替换）
     */
    public function setTimeRanges(int $planId, array $ranges): array;

    /**
     * 获取录像计划的时间段
     */
    public function getTimeRanges(int $planId): array;

    /**
     * 将通道绑定到录像计划
     */
    public function bindChannels(int $planId, array $channelIds): int;

    /**
     * 解绑通道的录像计划
     */
    public function unbindChannel(int $channelId): bool;

    /**
     * 批量解绑通道的录像计划
     */
    public function unbindChannels(array $channelIds): int;

    /**
     * 获取录像计划已绑定的通道列表
     */
    public function getBoundChannels(int $planId): array;

    /**
     * 获取所有计划（含 time_range_list）
     */
    public function getPlansWithRanges(): array;
}
