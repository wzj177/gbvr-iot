<?php

namespace CoreW\Business\Alarm\Service\Impl;

use CoreW\Business\Alarm\Exception\AlarmException;
use CoreW\Business\Alarm\Service\AlarmEventService;
use CoreW\Business\Alarm\Dao\AlarmEventDao;
use CoreW\Business\Alarm\Dao\AlarmPlanDao;
use CoreW\Business\BaseService;
use CoreW\Dao\DaoProxy;
use support\Log;

class AlarmEventServiceImpl extends BaseService implements AlarmEventService
{
    public function handleAlarmNotify(array $alarmData): array
    {
        $deviceId = $alarmData['device_id'] ?? '';
        $channelId = $alarmData['channel_id'] ?? $deviceId;

        // 验证设备/通道是否存在
        $channel = $this->getDeviceService()->getChannel($deviceId, $channelId);
        if (!$channel) {
            throw new \RuntimeException("Device or channel not found: {$deviceId}/{$channelId}");
        }

        // 解析报警时间
        $alarmTime = $alarmData['alarm_time'] ?? 'now';
        if (!is_numeric($alarmTime)) {
            // ISO8601格式或datetime格式
            $alarmTimeTs = strtotime($alarmTime);
        } else {
            $alarmTimeTs = (int)$alarmTime;
        }

        // 创建报警事件记录
        $event = [
            'device_id' => $deviceId,
            'channel_id' => $channelId,
            'level' => $alarmData['level'] ?? $alarmData['priority'] ?? 1,
            'method' => $alarmData['method'] ?? $alarmData['alarm_method'] ?? 1,
            'type' => $alarmData['type'] ?? null,
            'eventtype' => $alarmData['eventtype'] ?? null,
            'description' => $alarmData['description'] ?? null,
            'longitude' => $alarmData['longitude'] ?? null,
            'latitude' => $alarmData['latitude'] ?? null,
            'alarm_time' => date('Y-m-d H:i:s', $alarmTimeTs),
            'recv_time' => date('Y-m-d H:i:s'),
            'raw_payload' => $alarmData['raw_payload'] ?? json_encode($alarmData, JSON_UNESCAPED_UNICODE),
        ];

        $alarmEvent = $this->getAlarmEventDao()->create($event);

        // 查找匹配的报警预案
        $matchedPlan = $this->findMatchedAlarmPlan($alarmEvent);

        if ($matchedPlan) {
            // 更新报警事件的关联预案
            $this->getAlarmEventDao()->update($alarmEvent['id'], [
                'alarm_plan_id' => $matchedPlan['id'],
            ]);
            $alarmEvent['alarm_plan_id'] = $matchedPlan['id'];

            // 执行报警联动动作
            $this->executeAlarmPlanActions($alarmEvent, $matchedPlan);
        }

        return $alarmEvent;
    }

    public function searchAlarmEvents(array $conditions, array $orderBys = [], int $start = 0, int $limit = 20): array
    {
        return $this->getAlarmEventDao()->search($conditions, $orderBys, $start, $limit);
    }

    public function countAlarmEvents(array $conditions): int
    {
        return $this->getAlarmEventDao()->count($conditions);
    }

    public function getAlarmEvent(int $id): ?array
    {
        return $this->getAlarmEventDao()->get($id);
    }

    public function updateAlarmEvent(int $id, array $data): array
    {
        $event = $this->getAlarmEventDao()->get($id);

        if (!$event) {
            throw AlarmException::NOTFOUND_ALARM_EVENT();
        }

        if (empty($data)) {
            throw AlarmException::ERROR_PARAMETER();
        }

        return $this->getAlarmEventDao()->update($id, $data);
    }

    public function findMatchedAlarmPlan(array $alarmEvent): ?array
    {
        $deviceId = $alarmEvent['device_id'];
        $channelId = $alarmEvent['channel_id'];

        // 查询关联到此设备和通道的启用预案
        $plans = $this->getAlarmPlanDao()->getPlansByDeviceAndChannel($deviceId, $channelId);

        foreach ($plans as $plan) {
            if ($this->isAlarmPlanMatched($alarmEvent, $plan)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * 检查报警事件是否匹配预案规则
     */
    private function isAlarmPlanMatched(array $alarmEvent, array $plan): bool
    {
        $level = $alarmEvent['level'];
        $method = $alarmEvent['method'];
        $type = $alarmEvent['type'] ?? null;
        $eventtype = $alarmEvent['eventtype'] ?? null;

        // 检查级别匹配
        if (!empty($plan['alarm_level'])) {
            $levelRules = $plan['alarm_level'];
            if (!in_array($level, $levelRules)) {
                return false;
            }
        }

        // 检查方式匹配
        if (!empty($plan['alarm_method'])) {
            $methodRules = $plan['alarm_method'];
            if (!in_array($method, $methodRules)) {
                return false;
            }
        }

        // 检查类型匹配
        if ($type !== null && !empty($plan['alarm_type'])) {
            $typeRules = $plan['alarm_type'];
            if (!in_array($type, $typeRules)) {
                return false;
            }
        }

        // 检查事件类型匹配
        if ($eventtype !== null && !empty($plan['alarm_eventtype'])) {
            $eventtypeRules = $plan['alarm_eventtype'];
            if (!in_array($eventtype, $eventtypeRules)) {
                return false;
            }
        }

        return true;
    }

    public function executeAlarmPlanActions(array $alarmEvent, array $alarmPlan): void
    {
        $deviceId = $alarmEvent['device_id'];
        $channelId = $alarmEvent['channel_id'];
        $alarmEventId = $alarmEvent['id'];

        $recordDuration = (int)($alarmPlan['record_duration_sec'] ?? 0);
        $snapshotInterval = (int)($alarmPlan['snapshot_interval_sec'] ?? 0);

        Log::channel('sip')->info('Executing alarm plan actions', [
            'alarm_event_id' => $alarmEventId,
            'plan_id' => $alarmPlan['id'],
            'record_duration' => $recordDuration,
            'snapshot_interval' => $snapshotInterval,
        ]);

        // 1. 如果配置了录像，创建录像任务
        if ($recordDuration > 0) {
            try {
                $customizedPath = sprintf('/alarm/%s/%s', date('Y/m'), $alarmEventId);
                $recordTask = $this->getRecordTaskService()->createAlarmRecordTask(
                    $deviceId,
                    $channelId,
                    $recordDuration,
                    $customizedPath
                );

                Log::channel('sip')->info('Alarm record task created', [
                    'alarm_event_id' => $alarmEventId,
                    'task_id' => $recordTask['id'],
                    'duration' => $recordDuration,
                ]);
            } catch (\Exception $e) {
                Log::channel('sip')->error('Failed to create alarm record task', [
                    'alarm_event_id' => $alarmEventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 2. 如果配置了快照，立即抓拍一张
        if ($snapshotInterval > 0) {
            try {
                $snapshot = $this->getSnapshotFileService()->captureAlarmSnapshot(
                    $deviceId,
                    $channelId,
                    $alarmEventId
                );

                Log::channel('sip')->info('Alarm snapshot captured', [
                    'alarm_event_id' => $alarmEventId,
                    'snapshot_id' => $snapshot['id'] ?? null,
                ]);
            } catch (\Exception $e) {
                Log::channel('sip')->error('Failed to capture alarm snapshot', [
                    'alarm_event_id' => $alarmEventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function getAlarmEventDao(): AlarmEventDao|DaoProxy
    {
        return $this->createDao('Alarm:AlarmEventDao');
    }

    protected function getAlarmPlanDao(): AlarmPlanDao|DaoProxy
    {
        return $this->createDao('Alarm:AlarmPlanDao');
    }

    protected function getDeviceService()
    {
        return $this->createService('Devices:DeviceService');
    }

    protected function getRecordTaskService()
    {
        return $this->createService('Record:RecordTaskService');
    }

    protected function getSnapshotFileService()
    {
        return $this->createService('Snapshot:SnapshotFileService');
    }

    public function getSummary(): array
    {
        $db = $this->db();
        $table = 'gv_alarm_event';

        // 获取今天、本周、本月的开始时间戳
        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $weekStart = strtotime(date('Y-m-d 00:00:00', strtotime('this week Monday')));
        $monthStart = strtotime(date('Y-m-01 00:00:00'));

        // 统计总数
        $total = (int) $db->fetchOne("SELECT COUNT(*) FROM {$table}");

        // 统计今天
        $today = (int) $db->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE alarm_time >= ?",
            [$todayStart]
        );

        // 统计本周
        $week = (int) $db->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE alarm_time >= ?",
            [$weekStart]
        );

        // 统计本月
        $month = (int) $db->fetchOne(
            "SELECT COUNT(*) FROM {$table} WHERE alarm_time >= ?",
            [$monthStart]
        );

        return [
            'total' => $total,
            'today' => $today,
            'week' => $week,
            'month' => $month,
        ];
    }
}
