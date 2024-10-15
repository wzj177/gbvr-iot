<?php

namespace CoreW\Business\Product\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface ProductTourNodeDao extends AdvancedDaoInterface
{
    public function getAllByProductId(int $productId, array $fields = []);
    public function getAllByTourId(int $tourId, array $fields = []);
    public function getAllBySceneId(int $sceneId, array $fields = []);
}
