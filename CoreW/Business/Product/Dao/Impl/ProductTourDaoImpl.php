<?php

namespace CoreW\Business\Product\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Product\Dao\ProductTourDao;

class ProductTourDaoImpl extends AdvancedDaoImpl implements ProductTourDao
{

    protected $table = 'gv_product_tour';

    public function getByProductId(int $productId)
    {
        return $this->getByFields(['productId' => $productId]);
    }

    public function declares() : array
    {
        return [
            'serializes' => [
            ],
            'orderbys'   => [
                'id',
                'createdTime',
                'updatedTime',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'title = :title',
                'title LIKE :titleLike',
                'title PRE_LIKE :titlePreTitle',
                'createdTime >= :startTime',
                'createdTime <= :endTime',
                'productId = :productId',
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}
