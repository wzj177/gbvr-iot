<?php

namespace CoreW\Business\SystemLog\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\SystemLog\Dao\SystemLogDao;

class SystemLogDaoImpl extends AdvancedDaoImpl implements SystemLogDao
{

    protected $table = 'vr_log';

    protected function createUserJoinQueryBuilder($conditions)
    {
        $builder = $this->createQueryBuilder($conditions);
        $builder->leftJoin($this->table, 'vr_user', 'u', 'u.id = ' . $this->table . '.userID');

        return $builder;
    }


    public function search($conditions, $orderBys, $start, $limit = null, $columns = array())
    {
        return parent::search($conditions, $orderBys, $start, $limit, $columns);
//        if (empty($columns)) {
//            $columns = "vr_log.*,u.nickname as userName";
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
                'vr_log.id = :id',
                'vr_log.id >= :id_GE',
                'vr_log.id <= :id_LE',
                'vr_log.id IN (:ids)',
                'vr_log.module = :module',
                'vr_log.action = :action',
                'vr_log.level = :level',
                'vr_log.userId = :userId',
                'vr_log.userId IN (:userIds)',
                'vr_log.createdTime > :startDateTime',
                'vr_log.createdTime < :endDateTime',
                'vr_log.createdTime >= :startDateTime_GE',
                'vr_log.createdTime <= :startDateTime_LE',
                'vr_log.userId IN ( :userIds )',
                'vr_log.action IN ( :actions )',
                '(vr_log.action LIKE :keywordsLike OR vr_log.message LIKE :keywordsLike OR vr_log.ip LIKE :keywordsLike OR ipArea LIKE :keywordsLike)'
            ],
            'timestamps' => [
                'createdTime',
            ],
        ];
    }
}
