<?php

namespace app\admin\controller;

use app\admin\BaseController;
use CoreW\Business\User\Service\UserService;
use CoreW\Business\Role\Service\RoleService;
use support\Request;
use support\Response;

class UserController extends BaseController
{
    /**
     * 获取用户列表
     */
    public function index(Request $request) : Response
    {
        $conditions = $request->get();
        $start = (int)($request->get('start', 0));
        $limit = (int)($request->get('limit', 10));

        $orderBy = ['id' => 'DESC'];
        if (!empty($request->get('orderBy'))) {
            $orderBy = [$request->get('orderBy') => 'DESC'];
        }

        $total = $this->getUserService()->countUsers($conditions);
        $users = $this->getUserService()->searchUsers($conditions, $orderBy, $start, $limit);

        // 从当前页用户中提取所有不重复的 role codes
        $roleCodes = [];
        foreach ($users as $user) {
            $roleCodes = array_merge($roleCodes, $user['roles']);
        }
        $roleCodes = array_unique(array_filter($roleCodes));

        // 只查询当前页用户涉及的角色
        $roles = !empty($roleCodes) ? $this->getRoleService()->findRolesByCodes($roleCodes) : [];
        $roleMap = [];
        foreach ($roles as $role) {
            $roleMap[$role['code']] = [
                'name' => $role['name'],
                'code' => $role['code'],
            ];
        }

        // 移除敏感信息并转换 roles 格式
        $users = array_map(function ($user) use ($roleMap) {
            unset($user['password'], $user['salt']);
            $user['roles'] = $this->parseRoles($user['roles'] ?? [], $roleMap);
            return $user;
        }, $users);

        return $this->createSuccessJsonResponse([
            'total' => $total,
            'list'  => $users,
        ]);
    }

    /**
     * 获取单个用户
     */
    public function show(Request $request, $id) : Response
    {
        $id = (int)$id;
        if ($id === 1) {
            return $this->createErrorJsonResponse('不能查看默认用户');
        }

        $user = $this->getUserService()->getUser($id);

        if (empty($user)) {
            return $this->createErrorJsonResponse('用户不存在');
        }

        // 移除敏感信息
        unset($user['password'], $user['salt']);

        // 转换 roles 格式
        $allRoles = $this->getRoleService()->searchRoles([], 'created', 0, PHP_INT_MAX);
        $roleMap = [];
        foreach ($allRoles as $role) {
            $roleMap[$role['code']] = [
                'name' => $role['name'],
                'code' => $role['code'],
            ];
        }
        $user['roles'] = $this->parseRoles($user['roles'] ?? [], $roleMap);

        return $this->createSuccessJsonResponse($user);
    }

    public function showUuid(Request $request)
    {
        $user = $this->getUserService()->getUser($this->getCurrentUser()->getId());
        if (!$user) {
            return $this->createErrorJsonResponse('用户不存在');
        }

        return $this->createSuccessJsonResponse([
            'uuid' => $user['uuid'],
        ]);
    }

    /**
     * 创建用户
     */
    public function store(Request $request) : Response
    {
        $user = $request->post();

        // 验证必要字段
        if (empty($user['email']) || empty($user['nickname']) || empty($user['password'])) {
            return $this->createErrorJsonResponse('缺少必要参数');
        }

        // 检查邮箱是否已被使用
        if (!$this->getUserService()->isEmailAvaliable($user['email'])) {
            return $this->createErrorJsonResponse('邮箱已被使用');
        }

        // 检查昵称是否已被使用
        if (!$this->getUserService()->isNicknameAvaliable($user['nickname'])) {
            return $this->createErrorJsonResponse('昵称已被使用');
        }

        $user = $this->getUserService()->createUser($user);

        // 移除敏感信息
        unset($user['password'], $user['salt']);

        $this->getLogService()->info(LogEnum::MODULE_USER, LogEnum::ACTION_CREATE_USER, '创建用户', ['id' => $user['id']]);

        return $this->createSuccessJsonResponse($user);
    }

    /**
     * 更新用户
     */
    public function update(Request $request, $id) : Response
    {
        $fields = $request->post();

        // 移除不允许修改的字段
        unset($fields['password'], $fields['salt'], $fields['id'], $fields['uuid']);

        // 检查邮箱是否已被使用
        if (isset($fields['email'])) {
            $existingUser = $this->getUserService()->getUserByEmail($fields['email']);
            if ($existingUser && $existingUser['id'] != $id) {
                return $this->createErrorJsonResponse('邮箱已被使用');
            }
        }

        // 检查昵称是否已被使用
        if (isset($fields['nickname'])) {
            $existingUser = $this->getUserService()->getUserByNickname($fields['nickname']);
            if ($existingUser && $existingUser['id'] != $id) {
                return $this->createErrorJsonResponse('昵称已被使用');
            }
        }

        $user = $this->getUserService()->updateUser($id, $fields);

        // 移除敏感信息
        unset($user['password'], $user['salt']);

        $this->getLogService()->info(LogEnum::MODULE_USER, LogEnum::ACTION_UPDATE_USER, '更新用户', ['id' => $id, 'fields' => $fields]);

        return $this->createSuccessJsonResponse($user);
    }

    /**
     * 删除用户
     */
    public function destroy(Request $request, $id) : Response
    {
        $currentUser = $this->getCurrentUser();

        // 不允许删除自己
        if ((int)$id == $currentUser['id']) {
            return $this->createErrorJsonResponse('不能删除当前登录用户');
        }

        $this->getUserService()->deleteUserById($id);

        $this->getLogService()->info(LogEnum::MODULE_USER, LogEnum::ACTION_DELETE_USER, '删除用户', ['id' => $id]);

        return $this->createSuccessJsonResponse(['success' => true]);
    }

    /**
     * 分配角色给用户
     */
    public function assignRoles(Request $request, $id) : Response
    {
        $roles = $request->post('roles', []);

        if (!is_array($roles)) {
            return $this->createErrorJsonResponse('角色格式错误');
        }

        $currentUser = $this->getCurrentUser();
        $user = $this->getUserService()->changeUserRoles((int)$id, $roles, $currentUser);

        $this->getLogService()->info('user', 'assign_roles', '分配用户角色', [
            'userId' => $id,
            'roles'  => $roles,
        ]);

        return $this->createSuccessJsonResponse($user);
    }

    /**
     * 重置用户密码
     */
    public function resetPassword(Request $request, $id) : Response
    {
        $newPassword = $request->post('password', '');

        if (empty($newPassword)) {
            return $this->createErrorJsonResponse('密码不能为空');
        }

        // 验证密码强度
        if (!$this->getUserService()->validatePassword($newPassword)) {
            return $this->createErrorJsonResponse('密码强度不符合要求');
        }

        $currentIp = $request->getRealIp();
        $this->getUserService()->initPassword((int)$id, $newPassword, $currentIp);

        $this->getLogService()->info(LogEnum::MODULE_USER, LogEnum::ACTION_RESET_PASSWORD, '重置用户密码', ['id' => $id]);

        return $this->createSuccessJsonResponse(['success' => true]);
    }

    /**
     * 锁定/解锁用户
     */
    public function toggleLock(Request $request, $id) : Response
    {
        $locked = (bool)$request->post('locked', false);

        $user = $this->getUserService()->updateUser((int)$id, ['locked' => $locked ? 1 : 0]);

        $this->getLogService()->info(LogEnum::MODULE_USER, LogEnum::ACTION_TOGGLE_LOCK, $locked ? '锁定用户' : '解锁用户', ['id' => $id]);

        return $this->createSuccessJsonResponse($user);
    }

    /**
     * 批量删除用户
     */
    public function batchDelete(Request $request) : Response
    {
        $ids = $request->post('ids', []);
        if (empty($ids) || !is_array($ids)) {
            return $this->createErrorJsonResponse('请选择要删除的用户');
        }

        $currentUser = $this->getCurrentUser();
        foreach ($ids as $id) {
            // 不允许删除自己
            if ($id == $currentUser['id']) {
                continue;
            }
            $this->getUserService()->deleteUserById((int)$id);
        }

        $this->getLogService()->info(LogEnum::MODULE_USER, LogEnum::ACTION_BATCH_DELETE_USER, '批量删除用户', ['ids' => $ids]);

        return $this->createSuccessJsonResponse(['success' => true]);
    }

    /**
     * 获取用户角色选项
     */
    public function roleOptions(Request $request) : Response
    {
        $roles = $this->getRoleService()->searchRoles([], 'created', 0, PHP_INT_MAX);

        $options = array_map(function ($role) {
            return [
                'value' => $role['code'],
                'label' => $role['name'],
            ];
        }, $roles);

        return $this->createSuccessJsonResponse($options);
    }

    /**
     * 获取前端菜单配置（保留原有功能）
     */
    public function getMenuAdmin(Request $request) : Response
    {
        return $this->createSuccessJsonResponse(config('vue'), []);
    }

    /**
     * 解析用户角色数组为角色对象列表
     *
     * @param array $roleCodes 角色代码数组
     * @param array $roleMap 角色映射数组 [code => ['name' => xxx, 'code' => xxx]]
     * @return array
     */
    protected function parseRoles(array $roleCodes, array $roleMap) : array
    {
        if (empty($roleCodes)) {
            return [];
        }

        $roles = [];
        foreach ($roleCodes as $code) {
            if (isset($roleMap[$code])) {
                $roles[] = $roleMap[$code];
            }
        }

        return $roles;
    }

    /**
     * @return RoleService
     */
    protected function getRoleService() : RoleService
    {
        return $this->createService('Role:RoleService');
    }
}
