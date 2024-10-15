<?php


namespace CoreW\Dao;


class Connection extends \Doctrine\DBAL\Connection
{
    /**
     * 兼容
     *
     * @param $sql
     * @param array $params
     * @param array $types
     * @return array|false
     * @throws \Doctrine\DBAL\Exception
     */
    public function fetchAssoc($sql, array $params = [], array $types = [])
    {
        return $this->fetchAssociative($sql, $params, $types);
    }

    /***
     * 兼容 phpmig 包
     *
     * @deprecated use fetchAllAssociative
     *
     * @param string $query
     * @param array $params
     * @param array $types
     * @return array
     * @throws \Doctrine\DBAL\Exception
     */
    public function fetchAll(string $query, array $params = [], array $types = []): array
    {
        return $this->fetchAllAssociative($query, $params, $types);
    }

    public function update($tableExpression, array $data, array $identifier, array $types = array())
    {
        $this->checkFieldNames(array_keys($data));

        return parent::update($tableExpression, $data, $identifier, $types);
    }

    public function insert($tableExpression, array $data, array $types = array())
    {
        $this->checkFieldNames(array_keys($data));

        return parent::insert($tableExpression, $data, $types);
    }

    public function checkFieldNames($names)
    {
        foreach ($names as $name) {
            if (!ctype_alnum(str_replace('_', '', $name))) {
                throw new \InvalidArgumentException('Field name is invalid.');
            }
        }

        return true;
    }

    public function transactional(\Closure $func, \Closure $exceptionFunc = null)
    {
        $this->beginTransaction();
        try {
            $result = $func($this);
            $this->commit();

            return $result;
        } catch (\Exception $e) {
            $this->rollBack();
            !is_null($exceptionFunc) && $exceptionFunc($this);
            throw $e;
        }
    }
}