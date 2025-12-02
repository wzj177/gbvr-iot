<?php


namespace CoreW\Business\Product\Dao\Impl;


use CoreW\Business\Product\Dao\ProductCatalogTagDao;
use CoreW\Dao\AdvancedDaoImpl;

class ProductCatalogTagDaoImpl extends AdvancedDaoImpl implements ProductCatalogTagDao
{

    protected $table = 'gv_product_catalog_tag';

    public function getAllByCatalogId($catalogId): array
    {
        return $this->findByFields(['catalogId' => $catalogId]);
    }

    public function declares():array
    {
        return [
            'serializes' => [
            ],
            'orderbys' => [
                'id',
                'createdTime',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN (:ids)',
                'id NOT IN (:noIds)',
                'catalogId = :catalogId',
                'tagId = :tagId',
                'tagId IN (:tagIds)',
                'createdTime >= :startTime',
                'createdTime <= :endTime',
            ],
            'timestamps' => [
                'createdTime',
            ],
        ];
    }
}