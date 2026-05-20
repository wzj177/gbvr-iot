<?php


namespace CoreW\Business\Product\Dao;


use CoreW\Dao\AdvancedDaoInterface;

interface ProductCatalogDao extends AdvancedDaoInterface
{
    /**
     * @param string $name
     * @return array|null
     */
    public function getByName(string $name) : ?array;

    /**
     * @param string $code
     * @return array|null
     */
    public function getByCode(string $code) : ?array;
}