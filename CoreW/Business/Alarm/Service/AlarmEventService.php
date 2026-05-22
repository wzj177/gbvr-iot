<?php

namespace CoreW\Business\Alarm\Service;

interface AlarmEventService
{
    /**
     * 处理网关推送的报警事件
     *
     * @param array $alarmData 报警数据
     * @return array 创建的报警事件
     */
    public function handleAlarmNotify(array $alarmData) : array;

    /**
     * 查询报警事件列表
     *
     * @param array $conditions 查询条件
     * @param array $orderBys 排序
     * @param int $start 起始位置
     * @param int $limit 每页数量
     * @return array
     */
    public function searchAlarmEvents(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20) : array;

    /**
     * 统计报警事件数量
     *
     * @param array $conditions 查询条件
     * @return int
     */
    public function countAlarmEvents(array $conditions) : int;

    /**
     * 根据ID查询报警事件
     *
     * @param int $id
     * @return array|null
     */
    public function getAlarmEvent(int $id) : ?array;

    /**
     * 查找匹配的报警预案
     *
     * @param array $alarmEvent 报警事件
     * @return array|null 匹配的报警预案
     */
    public function findMatchedAlarmPlan(array $alarmEvent) : ?array;

    /**
     * 执行报警联动（录像+快照）
     *
     * @param array $alarmEvent 报警事件
     * @param array $alarmPlan 报警预案
     * @return void
     */
    public function executeAlarmPlanActions(array $alarmEvent, array $alarmPlan) : void;

    /**
     * 获取报警事件统计摘要
     *
     * @return array
     */
    public function getSummary() : array;

    /**
     * 更新报警事件
     *
     * @param int $id 报警事件ID
     * @param array $data 更新数据
     * @return array 更新后的报警事件
     */
    public function updateAlarmEvent(int $id, array $data) : array;

    /**
     * 获取报警关联的快照
     *
     * @param int $alarmEventId 报警事件ID
     * @return array 快照列表
     */
    public function getAlarmSnapshots(int $alarmEventId) : array;

    /**
     * 获取报警关联的录像
     *
     * @param int $alarmEventId 报警事件ID
     * @return array 录像列表
     */
    public function getAlarmRecords(int $alarmEventId) : array;
}
