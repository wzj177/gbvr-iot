<?php


namespace CoreW\Business\Product\Dao\Impl;


use CoreW\Business\Product\Dao\ProductCatalogDao;
use CoreW\Dao\AdvancedDaoImpl;

class ProductCatalogDaoImpl extends AdvancedDaoImpl implements ProductCatalogDao
{
    protected $table = 'gv_product_catalog';

    /**
     * @param string $name
     * @return array|null
     */
    public function getByName(string $name) : ?array
    {
        return $this->getByFields(['name' => $name]);
    }

    /**
     * @param string $code
     * @return array|null
     */
    public function getByCode(string $code) : ?array
    {
        return $this->getByFields(['code' => $code]);
    }

    public function declares() : array
    {
        return [
            'serializes' => [
            ],
            'orderbys'   => [
                'id',
                'sort',
                'parentId',
                'createdTime',
                'updatedTime',
            ],
            'conditions' => [
                'id = :id',
                'id > :id_GT',
                'id IN ( :ids)',
                'id NOT IN ( :noIds)',
                'name = :name',
                'parentId = :parentId',
                'parentId IN (:parentIds)',
                'path PRE_LIKE :preLikePath',
                'code = :code',
                'status = :status',
                '(name LIKE :keyword OR code LIKE :keyword)',
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