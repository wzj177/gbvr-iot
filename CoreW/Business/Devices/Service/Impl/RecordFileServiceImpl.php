<?php

namespace CoreW\Business\Devices\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Business\Devices\Service\RecordFileService;
use CoreW\Business\Devices\Dao\RecordFileDao;

class RecordFileServiceImpl extends BaseService implements RecordFileService
{
    public function getRecordFileById($id)
    {
        return $this->getRecordFileDao()->get($id);
    }

    public function getByMainId(string $mainId): ?array
    {
        return $this->getRecordFileDao()->getByMainId($mainId);
    }

    public function countRecordFiles(array $conditions)
    {
        return $this->getRecordFileDao()->count($conditions);
    }

    public function countRecordingsByDate(string $date): int
    {
        return $this->getRecordFileDao()->countByDate($date);
    }

    public function searchRecordFiles(array $conditions, array $orderBys, $start, $limit, $columns = [])
    {
        return $this->getRecordFileDao()->search($conditions, $orderBys, $start, $limit, $columns);
    }

    public function createRecordFile(array $fields)
    {
        $recordFile = array_merge([
            'created_time' => time(),
            'updated_time' => time(),
            'deleted_time' => 0,
        ], $fields);

        return $this->getRecordFileDao()->create($recordFile);
    }

    public function updateRecordFile($id, array $fields)
    {
        $fields['updated_time'] = time();
        return $this->getRecordFileDao()->update($id, $fields);
    }

    public function deleteRecordFileById($id)
    {
        return $this->getRecordFileDao()->delete($id);
    }

    protected function getRecordFileDao()
    {
        return $this->createDao('RecordFile:RecordFileDao');
    }
}
