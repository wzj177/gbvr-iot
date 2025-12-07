<?php


namespace CoreW\Dao;

use CoreW\Bfw as Biz;
use Doctrine\DBAL\Exception;


abstract class GeneralDaoImpl implements GeneralDaoInterface
{
    protected $biz;

    protected $table = null;

    public function __construct(Biz $biz)
    {
        $this->biz = $biz;
    }

    /**
     * @param $fields
     * @return |null
     * @throws DaoException
     */
    public function create($fields)
    {
        $affected = $this->db()->insert($this->table(), $fields);
        if ($affected <= 0) {
            throw $this->createDaoException('Insert error.');
        }

        $lastInsertId = $fields['id'] ?? $this->db()->lastInsertId();

        return $this->get($lastInsertId);
    }

    /**
     * @param $identifier
     * @param array $fields
     * @return int|null
     * @throws DaoException
     */
    public function update($identifier, array $fields)
    {
        if (empty($identifier)) {
            return 0;
        }

        if (is_numeric($identifier) || is_string($identifier)) {
            return $this->updateById($identifier, $fields);
        }

        if (is_array($identifier)) {
            $result = $this->updateByConditions($identifier, $fields);

            return $result->rowCount();
        }

        throw new DaoException('update arguments type error');
    }

    public function delete($id)
    {
        return $this->db()->delete($this->table(), array('id' => $id));
    }

    public function wave(array $ids, array $diffs)
    {
        $sets = array_map(
            function ($name) {
                return "{$name} = {$name} + ?";
            },
            array_keys($diffs)
        );

        $marks = str_repeat('?,', count($ids) - 1) . '?';

        $sql = "UPDATE {$this->table()} SET " . implode(', ', $sets) . " WHERE id IN ($marks)";

//        return $this->db()->executeUpdate($sql, array_merge(array_values($diffs), $ids));
        return $this->db()->executeStatement($sql, array_merge(array_values($diffs), $ids));
    }

    public function increment($id, $field, $value = 1): int
    {
        $sql = "UPDATE {$this->table()} SET {$field} = {$field} + ? WHERE id = ?";
        try {
            return $this->db()->executeStatement($sql, [$value, $id]);
        } catch (Exception $e) {
            return 0;
        }
    }

    public function decrement($id, $field, $value = 1): int
    {
        $sql = "UPDATE {$this->table()} SET {$field} = {$field} - ? WHERE id = ?";
        try {
            return $this->db()->executeStatement($sql, [$value, $id]);
        } catch (Exception $e) {
            return 0;
        }
    }

    public function get($id, array $options = array())
    {
        $lock = isset($options['lock']) && true === $options['lock'];
        $sql = "SELECT * FROM {$this->table()} WHERE id = ?" . ($lock ? ' FOR UPDATE' : '');

        if ($lock) {
            $this->db()->connect('master');
        }

        return $this->db()->fetchAssoc($sql, array($id)) ?: null;
    }

    public function search($conditions, $orderBys, $start, $limit = null, $columns = array())
    {
        /** @var $builder DynamicQueryBuilder */
        $builder = $this->createQueryBuilder($conditions)
            ->setFirstResult($start)
            ->setMaxResults($limit);

        $this->addSelect($builder, $columns);
        $builder = $this->checkAndAddOrders($builder, $orderBys);

        return $builder->execute()->fetchAll();
    }

    protected function checkAndAddOrders($builder, $orderBys)
    {
        $declares = $this->declares();
        foreach ($orderBys ?: array() as $order => $sort) {
            $this->checkOrderBy($order, $sort, $declares['orderbys']);
            $builder->addOrderBy($order, $sort);
        }

        return $builder;
    }

    protected function addOrders($builder, $orderBys)
    {
        foreach ($orderBys ?: array() as $order => $sort) {
            $builder->addOrderBy($order, $sort);
        }

        return $builder;
    }

    protected function addSelect(DynamicQueryBuilder $builder, $columns)
    {
        if (!$columns) {
            return $builder->select('*');
        }

        foreach ($columns as $column) {
            if (preg_match('/^\w+$/', $column)) {
                $builder->addSelect($column);
            } else {
                throw $this->createDaoException('Illegal column name: ' . $column);
            }
        }

        return $builder;
    }

    public function count($conditions)
    {
        $builder = $this->createQueryBuilder($conditions)
            ->select('COUNT(*) as total');

        return (int)$builder->execute()->fetchOne();
    }

    protected function updateById($id, $fields)
    {
        $this->db()->update($this->table, $fields, array('id' => $id));

        return $this->get($id);
    }

    /**
     * @param array $conditions conditions of need update rows
     * @param array $fields updated values
     *
     * @return int the number of affected rows
     */
    protected function updateByConditions(array $conditions, array $fields)
    {
        $builder = $this->createQueryBuilder($conditions)
            ->update($this->table, $this->table);

        foreach ($fields as $key => $value) {
            $builder
                ->set($key, ':' . $key)
                ->setParameter($key, $value);
        }

        return $builder->execute();
    }

    /**
     * @param string $sql
     * @param array $orderBys
     * @param int $start
     * @param int $limit
     *
     * @return string
     * @throws DaoException
     *
     */
    protected function sql($sql, array $orderBys = array(), $start = null, $limit = null)
    {
        if (!empty($orderBys)) {
            $sql .= ' ORDER BY ';
            $orderByStr = $separate = '';
            $declares = $this->declares();
            foreach ($orderBys as $order => $sort) {
                $this->checkOrderBy($order, $sort, $declares['orderbys']);
                $orderByStr .= sprintf('%s %s %s', $separate, $order, $sort);
                $separate = ',';
            }

            $sql .= $orderByStr;
        }

        if (null !== $start && !is_numeric($start)) {
            throw $this->createDaoException('SQL Limit must can be cast to integer');
        }

        if (null !== $limit && !is_numeric($limit)) {
            throw $this->createDaoException('SQL Limit must can be cast to integer');
        }

        $onlySetStart = null !== $start && null === $limit;
        $onlySetLimit = null !== $limit && null === $start;

        if ($onlySetStart || $onlySetLimit) {
            throw $this->createDaoException('start and limit need to be assigned');
        }

        if (is_numeric($start) && is_numeric($limit)) {
            $sql .= sprintf(' LIMIT %d, %d', $start, $limit);
        }

        return $sql;
    }

    public function table()
    {
        return $this->table;
    }

    /**
     * @return \Doctrine\DBAL\Connection
     */
    public function db()
    {
        return $this->biz['db'];
    }

    protected function getByFields($fields)
    {
        $placeholders = array_map(
            function ($name) {
                return "{$name} = ?";
            },
            array_keys($fields)
        );

        $sql = "SELECT * FROM {$this->table()} WHERE " . implode(' AND ', $placeholders) . ' LIMIT 1; ';

        return $this->db()->fetchAssoc($sql, array_values($fields)) ?: null;
    }

    protected function findInField($field, $values)
    {
        if (empty($values)) {
            return array();
        }

        $marks = str_repeat('?,', count($values) - 1) . '?';
        $sql = "SELECT * FROM {$this->table} WHERE {$field} IN ({$marks});";

        return $this->db()->fetchAllAssociative($sql, $values);
//        return $this->db()->fetchAll($sql, $values);
    }

    protected function findByFields($fields)
    {
        $placeholders = array_map(
            function ($name) {
                return "{$name} = ?";
            },
            array_keys($fields)
        );

        $sql = "SELECT * FROM {$this->table()} WHERE " . implode(' AND ', $placeholders);

        return $this->db()->fetchAllAssociative($sql, array_values($fields));
    }

    protected function createQueryBuilder($conditions): DynamicQueryBuilder
    {
        $conditions = array_filter(
            $conditions,
            function ($value) {
                if ('' === $value || null === $value) {
                    return false;
                }

                if (is_array($value) && empty($value)) {
                    return false;
                }

                return true;
            }
        );

        $builder = $this->getQueryBuilder($conditions);
        $builder->from($this->table(), $this->table());

        $declares = $this->declares();
        $declares['conditions'] = $declares['conditions'] ?? [];

        foreach ($declares['conditions'] as $condition) {
            $builder->andWhere($condition);
        }

        return $builder;
    }

    protected function getQueryBuilder($conditions)
    {
        return new DynamicQueryBuilder($this->db(), $conditions);
    }

    protected function filterStartLimit(&$start, &$limit)
    {
        $start = (int)$start;
        $limit = (int)$limit;
    }

    private function createDaoException($message = '', $code = 0)
    {
        return new DaoException($message, $code);
    }

    private function checkOrderBy($order, $sort, $allowOrderBys)
    {
        if (!in_array($order, $allowOrderBys, true)) {
            throw $this->createDaoException(
                sprintf("SQL order by field is only allowed '%s', but you give `{$order}`.", implode(',', $allowOrderBys))
            );
        }
        if (!in_array(strtoupper($sort), array('ASC', 'DESC'), true)) {
            throw $this->createDaoException("SQL order by direction is only allowed `ASC`, `DESC`, but you give `{$sort}`.");
        }
    }

    public function pickIdAndUpdatedTimesByUpdatedTimeGT($timestamp, $start, $limit, $updatedTimeColumn = 'updatedTime')
    {
        return $this->db()->fetchAllAssociative(
            $this->sql("SELECT id, {$updatedTimeColumn} FROM {$this->table()} WHERE {$updatedTimeColumn} > ?", array($updatedTimeColumn => 'ASC'), $start, $limit),
            array($timestamp)
        );
    }
}