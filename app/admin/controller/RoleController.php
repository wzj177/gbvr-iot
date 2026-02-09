<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Role\Service\RoleService;
use CoreW\Business\Menu\Service\MenuService;
use CoreW\Business\SystemLog\LogEnum;
use support\Request;
use support\Response;

class RoleController extends BaseController
{
    /**
     * 获取角色列表
     */
    public function index(Request $request): Response
    {
        $conditions = $request->get();
        $start = (int) ($request->get('start', 0));
        $limit = (int) ($request->get('limit', 10));
        $sort = $request->get('sort', 'created');

        $total = $this->getRoleService()->searchRolesCount($conditions);
        $roles = $this->getRoleService()->searchRoles($conditions, $sort, $start, $limit);

        return $this->createSuccessJsonResponse([
            'total' => $total,
            'list' => $roles,
        ]);
    }

    /**
     * 获取单个角色
     */
    public function show(Request $request, $id): Response
    {
        $id = (int) $id;
        $role = $this->getRoleService()->getRole($id);

        if (empty($role)) {
            return $this->createErrorJsonResponse('角色不存在');
        }

        // 获取角色的菜单权限（从 data 字段）
        $menuIds = $role['data']['menuIds'] ?? [];
        $role['menuIds'] = $menuIds;

        return $this->createSuccessJsonResponse($role);
    }

    /**
     * 创建角色
     */
    public function store(Request $request): Response
    {
        $role = $request->post();

        // 分离菜单权限
        $menuIds = $role['menuIds'] ?? [];
        unset($role['menuIds']);

        // 将 menuIds 存入 data 字段
        $role['data'] = ['menuIds' => $menuIds];

        $role = $this->getRoleService()->createRole($role);

        $this->getLogService()->info(LogEnum::MODULE_ROLE, LogEnum::ACTION_CREATE_ROLE, '创建角色', $role);

        return $this->createSuccessJsonResponse($role);
    }

    /**
     * 更新角色
     */
    public function update(Request $request, $id): Response
    {
        $id = (int) $id;
        $fields = $request->post();

        // 分离菜单权限
        $menuIds = $fields['menuIds'] ?? null;
        unset($fields['menuIds']);

        // 如果 menuIds 有变化，更新 data 字段
        if ($menuIds !== null) {
            $existingRole = $this->getRoleService()->getRole($id);
            $existingData = $existingRole['data'] ?? [];
            $fields['data'] = array_merge($existingData, ['menuIds' => $menuIds]);
        }

        $role = $this->getRoleService()->updateRole($id, $fields);

        $this->getLogService()->info(LogEnum::MODULE_ROLE, LogEnum::ACTION_UPDATE_ROLE, '更新角色', ['id' => $id, 'fields' => $fields]);

        return $this->createSuccessJsonResponse($role);
    }

    /**
     * 删除角色
     */
    public function destroy(Request $request, $id): Response
    {
        $id = (int) $id;
        $this->getRoleService()->deleteRole($id);

        $this->getLogService()->info(LogEnum::MODULE_ROLE, LogEnum::ACTION_DELETE_ROLE, '删除角色', ['id' => $id]);

        return $this->createSuccessJsonResponse(['success' => true]);
    }

    /**
     * 获取角色的菜单权限
     */
    public function menus(Request $request, $id): Response
    {
        $id = (int) $id;
        $role = $this->getRoleService()->getRole($id);
        $menuIds = $role['data']['menuIds'] ?? [];

        $menus = [];
        if (!empty($menuIds)) {
            $menus = $this->getMenuService()->searchMenus(
                ['ids' => $menuIds],
                'sort',
                0,
                PHP_INT_MAX
            );
        }

        return $this->createSuccessJsonResponse($menus);
    }

    /**
     * 分配菜单权限给角色
     */
    public function assignMenus(Request $request, $id): Response
    {
        $id = (int) $id;
        $menuIds = $request->post('menuIds', []);

        if (!is_array($menuIds)) {
            return $this->createErrorJsonResponse('菜单ID格式错误');
        }

        $role = $this->getRoleService()->getRole($id);
        $existingData = $role['data'] ?? [];
        $updatedData = array_merge($existingData, ['menuIds' => $menuIds]);

        $this->getRoleService()->updateRole($id, ['data' => $updatedData]);

        $this->getLogService()->info('role', 'assign_menus', '分配菜单权限', [
            'roleId' => $id,
            'menuIds' => $menuIds,
        ]);

        return $this->createSuccessJsonResponse(['success' => true]);
    }

    /**
     * 批量删除角色
     */
    public function batchDelete(Request $request): Response
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return $this->createErrorJsonResponse('请选择要删除的角色');
        }

        foreach ($ids as $id) {
            $this->getRoleService()->deleteRole((int) $id);
        }

        $this->getLogService()->info(LogEnum::MODULE_ROLE, LogEnum::ACTION_BATCH_DELETE_ROLE, '批量删除角色', ['ids' => $ids]);

        return $this->createSuccessJsonResponse(['success' => true]);
    }

    /**
     * 获取角色选项（用于下拉框）
     */
    public function options(Request $request): Response
    {
        $roles = $this->getRoleService()->searchRoles([], 'created', 0, PHP_INT_MAX);

        $options = array_map(function ($role) {
            return [
                'value' => $role['id'],
                'label' => $role['name'],
                'code' => $role['code'],
            ];
        }, $roles);

        return $this->createSuccessJsonResponse($options);
    }

    protected function getRoleService(): RoleService
    {
        return $this->createService('Role:RoleService');
    }

    protected function getMenuService(): MenuService
    {
        return $this->createService('Menu:MenuService');
    }
}
