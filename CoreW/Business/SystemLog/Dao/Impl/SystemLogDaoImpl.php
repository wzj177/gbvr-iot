<?php

namespace CoreW\Business\SystemLog\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\SystemLog\Dao\SystemLogDao;

class SystemLogDaoImpl extends AdvancedDaoImpl implements SystemLogDao
{

    protected $table = 'gv_log';

    protected function createUserJoinQueryBuilder($conditions): \CoreW\Dao\DynamicQueryBuilder
    {
        $builder = $this->createQueryBuilder($conditions);
        $builder->leftJoin($this->table, 'gv_user', 'u', 'u.id = ' . $this->table . '.userID');

        return $builder;
    }


    public function search($conditions, $orderBys, $start, $limit = null, $columns = array())
    {
        return parent::search($conditions, $orderBys, $start, $limit, $columns);
//        if (empty($columns)) {
//            $columns = "gv_log.*,u.nickname as userName";
//        }
//
//        $builder = $this->createUserJoinQueryBuilder($conditions)
//            ->setFirstResult($start)
//            ->setMaxResults($limit)
//            ->addSelect($columns);
//        foreach ($orderBys as $sort => $by) {
//            $builder->addOrderBy($sort, $by);
//        }
//
//        return $builder->execute()->fetchAll();
    }

    public function declares():array
    {
        return [
            'serializes' => [
                'data' => 'json',
            ],
            'orderbys' => [
                'id',
                'createdTime',
            ],
            'conditions' => [
                'gv_log.id = :id',
                'gv_log.id >= :id_GE',
                'gv_log.id <= :id_LE',
                'gv_log.id IN (:ids)',
                'gv_log.module = :module',
                'gv_log.action = :action',
                'gv_log.level = :level',
                'gv_log.userId = :userId',
                'gv_log.userId IN (:userIds)',
                'gv_log.createdTime > :startDateTime',
                'gv_log.createdTime < :endDateTime',
                'gv_log.createdTime >= :startDateTime_GE',
                'gv_log.createdTime <= :startDateTime_LE',
                'gv_log.userId IN ( :userIds )',
                'gv_log.action IN ( :actions )',
                '(gv_log.action LIKE :keywordsLike OR gv_log.message LIKE :keywordsLike OR gv_log.ip LIKE :keywordsLike OR ipArea LIKE :keywordsLike)'
            ],
            'timestamps' => [
                'createdTime',
            ],
        ];
    }
}
