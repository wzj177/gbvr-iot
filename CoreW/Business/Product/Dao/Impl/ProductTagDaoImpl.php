<?php

namespace CoreW\Business\Product\Dao\Impl;

use CoreW\Business\Product\Dao\ProductTagDao;
use CoreW\Dao\AdvancedDaoImpl;

class ProductTagDaoImpl extends AdvancedDaoImpl implements ProductTagDao
{
    protected $table = 'gv_product_tag';

    public function declares() : array
    {
        return [
            'serializes' => [
            ],
            'orderbys'   => [
                'productId',
                'tagId',
            ],
            'conditions' => [
                'tagId = :tagId',
                'productId = :productId',
                'productId IN (:productIds)',
            ],
            'timestamps' => [
            ],
        ];
    }
}