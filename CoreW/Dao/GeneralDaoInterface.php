<?php


namespace CoreW\Dao;


interface GeneralDaoInterface extends DaoInterface
{
    public function create($fields);

    /**
     * @param $identifier
     * @param array $fields
     * @return int|null
     * @throws DaoException
     */
    public function update($identifier, array $fields);

    public function delete($id);

    public function get($id, array $options = array());

    public function search($conditions, $orderBys, $start, $limit = null, $columns = array());

    public function count($conditions);

    public function wave(array $ids, array $diffs);

    public function table();

    public function increment($id, $field, $value): int;

    public function decrement($id, $field, $value): int;
}