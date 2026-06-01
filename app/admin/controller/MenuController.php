<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\Menu\Service\MenuService;
use support\Request;
use support\Response;
use CoreW\Business\SystemLog\LogEnum;

class MenuController extends BaseController
{
    /**
     * 获取菜单列表
     */
    public function index(Request $request) : Response
    {
        $conditions = $request->get();
        $start = (int)($request->get('start', 0));
        $limit = (int)($request->get('limit', 10));
        $sort = $request->get('sort', 'sort');

        $total = $this->getMenuService()->searchMenusCount($conditions);
        $menus = $this->getMenuService()->searchMenus($conditions, $sort, $start, $limit);

        return $this->createSuccessJsonResponse([
            'total' => $total,
            'list'  => $menus,
        ]);
    }

    /**
     * 获取单个菜单
     */
    public function show(Request $request, $id) : Response
    {
        $id = (int)$id;
        $menu = $this->getMenuService()->getMenu($id);

        if (empty($menu)) {
            return $this->createErrorJsonResponse('菜单不存在');
        }

        return $this->createSuccessJsonResponse($menu);
    }

    /**
     * 创建菜单
     */
    public function store(Request $request) : Response
    {
        $menu = $request->post();
        $menu = $this->getMenuService()->createMenu($menu);

        $this->getLogService()->info(LogEnum::MODULE_MENU, LogEnum::ACTION_CREATE_MENU, '创建菜单', $menu);

        return $this->createSuccessJsonResponse($menu);
    }

    /**
     * 更新菜单
     */
    public function update(Request $request, $id) : Response
    {
        $id = (int)$id;
        $fields = $request->post();

        $menu = $this->getMenuService()->updateMenu($id, $fields);

        $this->getLogService()->info(LogEnum::MODULE_MENU, LogEnum::ACTION_UPDATE_MENU, '更新菜单', ['id' => $id, 'fields' => $fields]);

        return $this->createSuccessJsonResponse($menu);
    }

    /**
     * 删除菜单
     */
    public function destroy(Request $request, $id) : Response
    {
        $id = (int)$id;
        $this->getMenuService()->deleteMenu($id);

        $this->getLogService()->info(LogEnum::MODULE_MENU, LogEnum::ACTION_DELETE_MENU, '删除菜单', ['id' => $id]);

        return $this->createSuccessJsonResponse(['success' => true]);
    }

    /**
     * 获取菜单树
     */
    public function tree(Request $request) : Response
    {
        $tree = $this->getMenuService()->getMenuTree();
        return $this->createSuccessJsonResponse($tree);
    }

    /**
     * 同步菜单（从 menu.json）
     */
    public function sync(Request $request) : Response
    {
        $menuFile = base_path('migrations/seeders/default-menu.json');
        if (!file_exists($menuFile)) {
            return $this->createErrorJsonResponse('菜单文件不存在');
        }

        $menuData = json_decode(file_get_contents($menuFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->createErrorJsonResponse('菜单文件格式错误');
        }

        $this->getMenuService()->syncMenusFromJson($menuData);

        $this->getLogService()->info(LogEnum::MODULE_MENU, LogEnum::ACTION_SYNC_MENU, '同步菜单', ['file' => $menuFile]);

        return $this->createSuccessJsonResponse(['success' => true]);
    }

    /**
     * 获取当前用户的菜单树
     */
    public function userMenu(Request $request) : Response
    {
        $user = $this->getCurrentUser();
        $roles = $user['roles'] ?? [];

        if (empty($roles)) {
            return $this->createSuccessJsonResponse([]);
        }


        $menuTree = $this->getMenuService()->getMenuTreeByRoleCodes($roles);

        return $this->createSuccessJsonResponse($menuTree);
    }

    /**
     * 批量删除菜单
     */
    public function batchDelete(Request $request) : Response
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return $this->createErrorJsonResponse('请选择要删除的菜单');
        }

        foreach ($ids as $id) {
            $this->getMenuService()->deleteMenu((int)$id);
        }

        $this->getLogService()->info(LogEnum::MODULE_MENU, LogEnum::ACTION_BATCH_DELETE_MENU, '批量删除菜单', ['ids' => $ids]);

        return $this->createSuccessJsonResponse(['success' => true]);
    }

    /**
     * 获取菜单类型选项
     */
    public function typeOptions(Request $request) : Response
    {
        $options = [
            ['value' => 'directory', 'label' => '目录'],
            ['value' => 'menu', 'label' => '菜单页'],
            ['value' => 'path', 'label' => '路径页'],
            ['value' => 'api', 'label' => 'API'],
        ];

        return $this->createSuccessJsonResponse($options);
    }

    protected function getMenuService() : MenuService
    {
        return $this->createService('Menu:MenuService');
    }
}
