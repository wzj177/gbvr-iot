<?php

namespace CoreW\Business\Devices\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Devices\Service\AlarmService;
use CoreW\Business\Devices\Dao\AlarmDao;

class AlarmServiceImpl extends BaseService implements AlarmService
{
    public function getAlarmById($id)
    {
        return $this->getAlarmDao()->get($id);
    }

    public function countAlarms(array $conditions)
    {
        return $this->getAlarmDao()->count($conditions);
    }

    public function countActiveAlarms(): int
    {
        return $this->getAlarmDao()->count(['handled_status' => 'pending']);
    }

    public function searchAlarms(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        return $this->getAlarmDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    public function createAlarm(array $fields)
    {
        $alarm = array_merge([
            'handled_status' => 'pending',
            'alarm_priority' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ], $fields);

        return $this->getAlarmDao()->create($alarm);
    }

    public function updateAlarm($id, array $fields)
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        return $this->getAlarmDao()->update($id, $fields);
    }

    public function updateAlarmStatus(int $id, string $status, ?string $action = null, ?string $remark = null): bool
    {
        $fields = [
            'handled_status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($status === 'handled' || $status === 'processing') {
            $fields['handled_at'] = date('Y-m-d H:i:s');
        }

        if ($action !== null) {
            $fields['handle_action'] = $action;
        }

        if ($remark !== null) {
            $fields['handle_remark'] = $remark;
        }

        return $this->getAlarmDao()->update($id, $fields) > 0;
    }

    public function deleteAlarmById($id)
    {
        return $this->getAlarmDao()->delete($id);
    }

    /**
     * @return AlarmDao
     */
    protected function getAlarmDao()
    {
        return $this->createDao('Alarm:AlarmDao');
    }
}
