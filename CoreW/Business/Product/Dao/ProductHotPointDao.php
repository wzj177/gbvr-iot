<?php

namespace CoreW\Business\Product\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface ProductHotPointDao extends AdvancedDaoInterface
{
    public function getByUUID(string $uuid);

    public function deleteBySceneId(int $sceneId);
}