<?php

namespace CoreW\Business\Menu\Dao;

use CoreW\Dao\AdvancedDaoInterface;

interface MenuDao extends AdvancedDaoInterface
{
    public function getByMenuId(string $menuId);

    public function findByMenuIds(array $menuIds);

    public function findByParentId(int $parentId);

    public function findByType(string $type);
}
