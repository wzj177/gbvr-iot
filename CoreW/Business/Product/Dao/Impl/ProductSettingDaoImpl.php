<?php

namespace CoreW\Business\Product\Dao\Impl;

use CoreW\Dao\AdvancedDaoImpl;
use CoreW\Business\Product\Dao\ProductSettingDao;

class ProductSettingDaoImpl extends AdvancedDaoImpl implements ProductSettingDao 
{

    protected $table = 'vr_product_setting';

    public function findByProductId(int $productId)
    {
       return $this->findByFields(['productId' => $productId]);
    }

    public function getByProductIdAndKey(int $productId, string $key)
    {
        return $this->getByFields(['productId' => $productId, 'name' => $key]);
    }

    public function declares():array
    {
        return [
            'serializes' => [
                'val' => 'json',
           ], 
            'orderbys' => [ 
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
                'name = :key',
           ],
            'timestamps' => [ 
                'createdTime',
                'updatedTime',
           ], 
        ];
    } 
}
