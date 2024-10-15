<?php

namespace CoreW\Business\Product\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface ProductSettingDao extends AdvancedDaoInterface
{
    public function findByProductId(int $productId);
    public function getByProductIdAndKey(int $productId, string $key);
}
