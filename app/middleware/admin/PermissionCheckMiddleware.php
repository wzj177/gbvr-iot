<?php

namespace app\middleware\admin;

use CoreW\Bfw;
use CoreW\Business\Menu\Service\MenuService;
use CoreW\Business\User\CurrentUserInterface;
use CoreW\Core;
use Webman\MiddlewareInterface;
use Webman\Http\Response;
use Webman\Http\Request;

/**
 * 权限检查中间件
 *
 * 检查用户是否有权限访问当前路由
 */
class PermissionCheckMiddleware implements MiddlewareInterface
{
    /**
     * 不需要权限检查的路由
     */
    protected array $exceptRoutes = [
        'admin.login',
        'admin.captcha',
        'admin.login_config',
        'admin.menu.user'
    ];

    /**
     * 超级管理员角色代码
     */
    protected string $superAdminRole = 'ROLE_SUPER_ADMIN';

    /**
     * 路由到权限映射（可选，用于显式映射）
     * 格式: 'route.name' => 'menu_id'
     */
    protected array $routePermissionMap = [];

    public function process(Request $request, callable $next): Response
    {
        $route = $request->route;
        $routeName = $route?->getName();
        // 如果没有路由名称，放行
        if (!$routeName) {
            return $next($request);
        }

        // 检查是否在例外列表中
        if ($this->shouldSkip($routeName)) {
            return $next($request);
        }

        // 获取当前用户
        $biz = $this->getBiz();
        /** @var CurrentUserInterface $currentUser */
        $currentUser = $biz['user'];
        if (!$currentUser || !$currentUser->isLogin()) {
            return $this->createFailJsonResponse('用户未登录', 401);
        }

        // 检查是否是超级管理员
        if ($this->isSuperAdmin($currentUser)) {
            return $next($request);
        }

        // 检查权限
        if (!$this->hasPermission($currentUser, $request, $routeName)) {
            return $this->createFailJsonResponse('没有权限访问', 403);
        }

        return $next($request);
    }

    /**
     * 检查是否应该跳过权限检查
     */
    protected function shouldSkip(string $routeName): bool
    {
        return in_array($routeName, $this->exceptRoutes);
    }

    /**
     * 检查是否是超级管理员
     */
    protected function isSuperAdmin(CurrentUserInterface $user): bool
    {
        $roles = $user['roles'] ?? [];
        if (is_string($roles)) {
            $roles = explode(',', $roles);
        }

        return in_array($this->superAdminRole, $roles);
    }

    /**
     * 检查用户是否有权限
     */
    protected function hasPermission(CurrentUserInterface $user, Request $request, string $routeName): bool
    {
        // 获取用户的角色代码
        $roleCodes = $user['roles'] ?? [];
        if (empty($roleCodes)) {
            return false;
        }

        // 统一处理为数组格式
        if (is_string($roleCodes)) {
            $roleCodes = array_filter(array_map('trim', explode(',', $roleCodes)));
        }

        // 获取当前路由对应的菜单权限
        $menuId = $this->getMenuIdByRoute($request, $routeName);
        if (!$menuId) {
            // 如果没有找到对应的菜单，可能该路由没有配置权限控制，放行
            return true;
        }

        // 获取角色的菜单树
        $menuTree = $this->getMenuService()->getMenuTreeByRoleCodes($roleCodes);
        if (empty($menuTree)) {
            return false;
        }

        // 扁平化菜单树，收集所有菜单ID
        $allowedMenuIds = $this->flattenMenuTree($menuTree);

        // 检查当前菜单ID是否在允许的列表中
        return in_array($menuId, $allowedMenuIds);
    }

    /**
     * 扁平化菜单树，收集所有菜单ID
     */
    protected function flattenMenuTree(array $menus): array
    {
        $menuIds = [];

        foreach ($menus as $menu) {
            $menuIds[] = $menu['id'];
            if (!empty($menu['children'])) {
                $menuIds = array_merge($menuIds, $this->flattenMenuTree($menu['children']));
            }
        }

        return $menuIds;
    }

    /**
     * 根据路由获取菜单ID
     */
    protected function getMenuIdByRoute(Request $request, string $routeName): ?int
    {
        // 首先检查显式映射
        if (isset($this->routePermissionMap[$routeName])) {
            $menuId = $this->getMenuService()->getMenuByMenuId($this->routePermissionMap[$routeName]);
            return $menuId ? $menuId['id'] : null;
        }

        // 根据路由名称查找菜单
        // 尝试从数据库中查找匹配的菜单
        $menus = $this->getMenuService()->searchMenus(
            ['routeName' => $routeName],
            'sort',
            0,
            1
        );

        if (!empty($menus)) {
            return $menus[0]['id'];
        }

        // 如果没有找到，尝试通过 HTTP 方法和路径匹配
        $method = $request->method();
        $path = $request->path();

        $menus = $this->getMenuService()->searchMenus(
            ['httpMethod' => $method, 'type' => 'api'],
            'sort',
            0,
            PHP_INT_MAX
        );

        foreach ($menus as $menu) {
            // 简单的路径匹配（可以扩展为正则匹配）
            if (strpos($path, rtrim($menu['path'], '/')) === 0) {
                return $menu['id'];
            }
        }

        return null;
    }

    /**
     * @return Bfw
     */
    protected function getBiz(): Bfw
    {
        return Core::instance();
    }

    protected function getMenuService(): MenuService
    {
        return $this->getBiz()->service('Menu:MenuService');
    }

    protected function createFailJsonResponse(string $message, int $code = 400): Response
    {
        return new Response($code, [
            'Content-Type' => 'application/json',
        ], json_encode([
            'code' => $code,
            'msg' => $message,
            'data' => null,
        ], JSON_UNESCAPED_UNICODE));
    }
}
