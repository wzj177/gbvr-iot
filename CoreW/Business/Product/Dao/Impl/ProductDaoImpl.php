<?php

namespace CoreW\Business\Product\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Product\Dao\ProductDao;

class ProductDaoImpl extends AdvancedDaoImpl implements ProductDao
{

    protected $table = 'vr_product';

    /**
     * @param string $code
     * @return null|array
     */
    public function getByCode(string $code): ?array
    {
        return $this->getByFields(['code' => $code]);
    }

    public function declares(): array
    {
        return [
            'serializes' => [
            ],
            'orderbys' => [
                'id',
                'createdTime',
                'updatedTime',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN ( :ids)',
                'id NOT IN ( :noIds)',
                'code = :code',
                'title = :title',
                'status = :status',
                'type = :type',
                'catalogId = :catalog_id',
                'title LIKE :keyword',
                'title PRE_LIKE :like_pre_title',
                'createdTime >= :startTime',
                'createdTime <= :endTime',
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}
