<?php

namespace CoreW\Business\Product\Dao\Impl;

use CoreW\Business\Product\Dao\ProductSceneDao;
use CoreW\Dao\AdvancedDaoImpl;

class ProductSceneDaoImpl extends AdvancedDaoImpl implements ProductSceneDao
{

    protected $table = 'gv_product_scene';

    /**
     * @param int $productId
     * @return mixed[]|\mixed[][]
     */
    public function getAllByProductId(int $productId)
    {
        return $this->getAll([
            'productId' => $productId
        ], ['number' => 'ASC']);
    }

    /**
     * @param int $productId
     * @param int $index
     * @return mixed
     */
    public function getByProductAndIndex(int $productId, int $index)
    {
        return $this->findByFields([
            'productId' => $productId,
            'number' => $index
        ]);
    }

    public function declares():array
    {
        return [
            'serializes' => [
                'vrOptions' => 'json'
            ],
            'orderbys' => [
                'id',
                'number',
                'productId',
                'createdTime',
                'updatedTime',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN ( :ids)',
                'id NOT IN ( :noIds)',
                'title = :title',
                'title LIKE :title',
                'title PRE_LIKE :likeTitle',
                'createdTime >= :startTime',
                'createdTime <= :endTime',
                'productId = :productId',
                'number = :number'
            ],
            'timestamps' => [
                'createdTime',
                'updatedTime',
            ],
        ];
    }
}