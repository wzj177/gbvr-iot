<?php

namespace CoreW\Business\Product\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface ProductDao extends AdvancedDaoInterface
{
    /**
     * @param string $code
     * @return null|array
     */
    public function getByCode(string $code): ?array;
}
