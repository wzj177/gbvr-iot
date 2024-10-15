<?php

namespace CoreW\Business\Product\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface ProductPlaneGraphDao extends AdvancedDaoInterface
{
    public function getByProductId(int $productId);
}
