<?php

namespace CoreW\Business\Product\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface ProductSceneDao extends AdvancedDaoInterface
{
    /**
     * @param int $productId
     * @param int $index
     * @return mixed
     */
    public function getByProductAndIndex(int $productId, int $index);


    /**
     * @param int $productId
     * @return mixed[]|\mixed[][]
     */
    public function getAllByProductId(int $productId);
}