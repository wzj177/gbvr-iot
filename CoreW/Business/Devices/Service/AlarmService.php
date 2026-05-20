<?php

namespace CoreW\Business\Devices\Service;

interface AlarmService
{
    public function getAlarmById($id);

    public function countAlarms(array $conditions);

    public function countActiveAlarms() : int;

    public function searchAlarms(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function createAlarm(array $fields);

    public function updateAlarm($id, array $fields);

    public function updateAlarmStatus(int $id, string $status, ?string $action = null, ?string $remark = null) : bool;

    public function deleteAlarmById($id);
}
