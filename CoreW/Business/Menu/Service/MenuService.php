<?php

namespace CoreW\Business\Menu\Service;

interface MenuService
{
    public function getMenu(int $id);

    public function getMenuByMenuId(string $menuId);

    public function createMenu(array $menu);

    public function updateMenu(int $id, array $fields);

    public function deleteMenu(int $id);

    public function searchMenus(array $conditions, string $sort, int $start, int $limit, array $columns = []);

    public function searchMenusCount(array $conditions);

    public function getMenuTree(array $conditions = []);

    public function getMenuTreeByRoleCodes(array $roleCodes);

    public function syncMenusFromJson(array $menuData);

    public function getMenusByType(string $type);
}
