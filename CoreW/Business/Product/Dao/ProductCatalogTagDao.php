<?php


namespace CoreW\Business\Product\Dao;


use CoreW\Dao\AdvancedDaoInterface;

interface ProductCatalogTagDao extends AdvancedDaoInterface
{
    /**
     * @param $catalogId
     * @return array
     */
    public function getAllByCatalogId($catalogId) : array;
}