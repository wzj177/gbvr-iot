<?php

namespace CoreW\Business\Devices\Service;

interface RecordFileService
{
    public function getRecordFileById($id);

    public function getByMainId(string $mainId): ?array;

    public function countRecordFiles(array $conditions);

    public function countRecordingsByDate(string $date): int;

    public function searchRecordFiles(array $conditions, array $orderBys, $start, $limit, $columns = []);

    public function createRecordFile(array $fields);

    public function updateRecordFile($id, array $fields);

    public function deleteRecordFileById($id);
}
