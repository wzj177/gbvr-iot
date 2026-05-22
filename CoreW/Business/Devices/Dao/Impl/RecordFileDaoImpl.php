<?php

namespace CoreW\Business\Devices\Dao\Impl;

use CoreW\Business\Devices\Dao\RecordFileDao;
use CoreW\Dao\AdvancedDaoImpl;

class RecordFileDaoImpl extends AdvancedDaoImpl implements RecordFileDao
{
    protected string $tableName = 'gv_record_file';
    protected string $primaryKey = 'id';

    public function getByMainId(string $mainId) : ?array
    {
        return $this->get(['main_id' => $mainId]);
    }

    public function countByDate(string $date) : int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->tableName} WHERE record_date = ?";
        $result = $this->db->fetchOne($sql, [$date]);
        return (int)($result['count'] ?? 0);
    }


    public function declares() : array
    {
        return [
            'serializes' => [
                'data' => 'json',
            ],
        ];
    }
}
