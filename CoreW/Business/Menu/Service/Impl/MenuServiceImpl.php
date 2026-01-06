<?php

namespace CoreW\Business\Menu\Service\Impl;

use CoreW\Business\BaseService;
use CoreW\Dao\DaoProxy;
use support\utils\ArrayToolkit;
use support\utils\TreeHelper;
use CoreW\Business\Common\CommonBizException;
use CoreW\Business\Menu\Service\MenuService;
use CoreW\Business\Menu\Dao\MenuDao;
use CoreW\Business\Role\Service\RoleService;

class MenuServiceImpl extends BaseService implements MenuService
{
    public function getMenu(int $id)
    {
        return $this->getMenuDao()->get($id);
    }

    public function getMenuByMenuId(string $menuId)
    {
        return $this->getMenuDao()->getByMenuId($menuId);
    }

    public function createMenu(array $menu)
    {
        $menu['createdTime'] = time();
        $menu['updatedTime'] = time();

        $menu = ArrayToolkit::parts($menu, [
            'menuId', 'name', 'icon', 'path', 'component',
            'parentId', 'parentMenuId', 'sort', 'type', 'httpMethod',
            'routeName', 'status', 'createdTime', 'updatedTime'
        ]);

        if (!ArrayToolkit::requireds($menu, ['menuId', 'name', 'type'])) {
            $this->createNewException(CommonBizException::ERROR_PARAMETER_MISSING());
        }

        // 检查 menuId 是否已存在
        $existing = $this->getMenuDao()->getByMenuId($menu['menuId']);
        if (!empty($existing)) {
            $this->createNewException(CommonBizException::ERROR_PARAMETER_DUPLICATE());
        }

        return $this->getMenuDao()->create($menu);
    }

    public function updateMenu(int $id, array $fields)
    {
        $menu = $this->getMenu($id);
        if (empty($menu)) {
            $this->createNewException(CommonBizException::ERROR_PARAMETER());
        }

        $fields = ArrayToolkit::parts($fields, [
            'name', 'icon', 'path', 'component',
            'parentId', 'parentMenuId', 'sort', 'type', 'httpMethod',
            'routeName', 'status'
        ]);

        if (isset($fields['menuId'])) {
            unset($fields['menuId']);
        }

        $fields['updatedTime'] = time();

        return $this->getMenuDao()->update($id, $fields);
    }

    public function deleteMenu(int $id)
    {
        $menu = $this->getMenu($id);
        if (empty($menu)) {
            $this->createNewException(CommonBizException::ERROR_PARAMETER());
        }

        // 检查是否有子菜单
        $children = $this->getMenuDao()->findByParentId($id);
        if (!empty($children)) {
            $this->createNewException(CommonBizException::ERROR_PARAMETER());
        }

        $this->getMenuDao()->delete($id);
    }

    public function searchMenus(array $conditions, string $sort, int $start, int $limit, array $columns = [])
    {
        $conditions = $this->prepareSearchConditions($conditions);

        switch ($sort) {
            case 'created':
                $orderby = ['createdTime' => 'DESC'];
                break;
            case 'createdByAsc':
                $orderby = ['createdTime' => 'ASC'];
                break;
            case 'sort':
                $orderby = ['sort' => 'ASC', 'id' => 'ASC'];
                break;
            default:
                $orderby = ['sort' => 'ASC', 'id' => 'ASC'];
                break;
        }

        return $this->getMenuDao()->search($conditions, $orderby, $start, $limit, $columns);
    }

    public function searchMenusCount(array $conditions)
    {
        $conditions = $this->prepareSearchConditions($conditions);
        return $this->getMenuDao()->count($conditions);
    }

    public function getMenuTree(array $conditions = [])
    {
        $menus = $this->getMenuDao()->search($conditions, ['sort' => 'ASC', 'id' => 'ASC'], 0, PHP_INT_MAX);

        return TreeHelper::referenceDeliveryTree($menus, 'id', 'parentId', 'children');
    }

    public function getMenuTreeByRoleCodes(array $roleCodes)
    {
        if (empty($roleCodes)) {
            return [];
        }

        // 统一处理为数组格式
        if (is_string($roleCodes)) {
            $roleCodes = array_filter(array_map('trim', explode(',', $roleCodes)));
        }

        $roleCodes = array_filter($roleCodes);
        if (empty($roleCodes)) {
            return [];
        }

        // 通过 role codes 获取角色
        $roles = $this->getRoleService()->findRolesByCodes($roleCodes);
        if (empty($roles)) {
            return [];
        }

        // 从所有角色的 data.menuIds 中收集菜单ID
        $menuIds = [];
        foreach ($roles as $role) {
            $roleMenuIds = $role['data']['menuIds'] ?? [];
            $menuIds = array_merge($menuIds, $roleMenuIds);
        }

        $menuIds = array_unique(array_filter($menuIds));
        if (empty($menuIds)) {
            return [];
        }

        // 获取菜单并构建树
        $menus = $this->getMenuDao()->search(['ids' => $menuIds, 'status' => 1], ['sort' => 'ASC', 'id' => 'ASC'], 0, PHP_INT_MAX);
        return TreeHelper::referenceDeliveryTree($menus, 'id', 'parentId', 'children');
    }

    public function syncMenusFromJson(array $menuData)
    {
        if (!isset($menuData['menus']) || !is_array($menuData['menus'])) {
            throw CommonBizException::ERROR_PARAMETER();
        }

        $existingMenus = $this->getMenuDao()->search([], [], 0, PHP_INT_MAX);
        $existingMenuIds = ArrayToolkit::column($existingMenus, 'menuId');

        $this->syncMenuRecursive($menuData['menus'], 0, '', $existingMenuIds);
    }

    public function getMenusByType(string $type)
    {
        return $this->getMenuDao()->findByType($type);
    }

    protected function syncMenuRecursive(array $menus, int $parentId, string $parentMenuId, array &$existingMenuIds)
    {
        foreach ($menus as $menu) {
            $menuId = $menu['id'];
            $data = [
                'menuId' => $menuId,
                'name' => $menu['name'] ?? '',
                'icon' => $menu['icon'] ?? '',
                'path' => $menu['path'] ?? '',
                'component' => $menu['component'] ?? '',
                'parentId' => $parentId,
                'parentMenuId' => $parentMenuId,
                'sort' => $menu['sort'] ?? 0,
                'type' => $menu['type'] ?? 'menu',
                'status' => 1,
            ];

            $existing = $this->getMenuDao()->getByMenuId($menuId);
            if (empty($existing)) {
                $newMenu = $this->createMenu($data);
                $newId = $newMenu['id'];
            } else {
                $this->updateMenu($existing['id'], $data);
                $newId = $existing['id'];
            }

            if (($key = array_search($menuId, $existingMenuIds)) !== false) {
                unset($existingMenuIds[$key]);
            }

            if (isset($menu['children']) && is_array($menu['children'])) {
                $this->syncMenuRecursive($menu['children'], $newId, $menuId, $existingMenuIds);
            }
        }
    }

    protected function prepareSearchConditions(array $conditions): array
    {
        if (isset($conditions['menuIdLike']) && !empty($conditions['menuIdLike'])) {
            $conditions['menuIdLike'] = "%{$conditions['menuIdLike']}%";
        }

        if (isset($conditions['nameLike']) && !empty($conditions['nameLike'])) {
            $conditions['nameLike'] = "%{$conditions['nameLike']}%";
        }

        if (isset($conditions['types']) && !is_array($conditions['types'])) {
            $conditions['types'] = explode(',', $conditions['types']);
        }

        return $conditions;
    }

    protected function getMenuDao(): MenuDao|DaoProxy
    {
        return $this->createDao('Menu:MenuDao');
    }

    protected function getRoleService(): RoleService
    {
        return $this->createService('Role:RoleService');
    }
}
