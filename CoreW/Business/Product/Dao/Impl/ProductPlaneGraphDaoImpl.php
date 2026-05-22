<?php

namespace CoreW\Business\Product\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Product\Dao\ProductPlaneGraphDao;

class ProductPlaneGraphDaoImpl extends AdvancedDaoImpl implements ProductPlaneGraphDao
{

    protected $table = 'gv_product_plane_graph';

    public function getByProductId(int $productId)
    {
        return $this->getByFields(['productId' => $productId]);
    }

    public function declares() : array
    {
        return [
            'serializes' => [
                'markers'  => 'json',
                'center'   => 'json',
                'gisParam' => 'json',
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
                'createdTime >= :startTime',
                'createdTime <= :endTime',
                'productId = :productId',
                'imgPath = :imgPath',
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}
