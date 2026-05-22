<?php


namespace CoreW\Dao;


interface AdvancedDaoInterface extends GeneralDaoInterface
{
    public function getAll(array $conditions, $orderBys = null, $columns = []);

    public function batchDelete(array $conditions);

    public function batchCreate($rows);

    public function batchUpdate($identifies, $updateColumnsList, $identifyColumn = 'id');
}