<?php

namespace CoreW\Business\Product\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface ProductTourDao extends AdvancedDaoInterface
{
    public function getByProductId(int $productId);
}
