<?php

namespace CoreW\Business\Product\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Product\Dao\ProductTourNodeDao;

class ProductTourNodeDaoImpl extends AdvancedDaoImpl implements ProductTourNodeDao 
{

    protected $table = 'gv_product_tour_node';

    public function getAllByProductId(int $productId, array $fields = [])
    {
        return $this->getAll(['productId' => $productId], null, $fields);
    }

    public function getAllByTourId(int $tourId, array $fields = [])
    {
        return $this->getAll(['tourId' => $tourId], null, $fields);
    }

    public function getAllBySceneId(int $sceneId, array $fields = [])
    {
        return $this->getAll(['sceneId' => $sceneId], null, $fields);
    }

    public function declares():array
    {
        return [
            'serializes' => [
                'position' => 'json',
           ], 
            'orderbys' => [ 
                'id',
                'idx',
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
                'tourId = :tourId',
                'sceneId = :sceneId',
                'idx = :index',
                'idx > :idx_GT',
            ],
            'timestamps' => [ 
                'createdTime',
                'updatedTime',
           ], 
        ];
    } 
}
