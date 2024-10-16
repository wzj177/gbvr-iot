<?php


namespace CoreW\Business\SystemLog;


use CoreW\Traits\EnumTrait;

class LogEnum
{
    use EnumTrait;

    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';

    public static function getLevelItems()
    {
        $items = [
            self::LEVEL_INFO => '提示',
            self::LEVEL_WARNING => '警告',
            self::LEVEL_ERROR => '错误'
        ];

        return $items;
    }

    const MODULE_ADMIN = 'admin';
    const MODULE_VIP = 'vip';
    const MODULE_ATTACHMENT = 'attachment';
    const MODULE_PRODUCT = 'product';
    const MODULE_PRODUCT_CATALOG = 'product_catalog';
    const MODULE_PRODUCT_TAG = 'product_tag';

    const MODULE_PRODUCT_SCENE = 'product_scene';
    const MODULE_SYSTEM = 'system';

    public static function getModuleItems()
    {
        $items = [
            self::MODULE_ADMIN => '系统管理员',
            self::MODULE_VIP => '会员',
            self::MODULE_ATTACHMENT => '附件',
            self::MODULE_PRODUCT => '作品',
            self::MODULE_PRODUCT_CATALOG => '作品分类',
            self::MODULE_PRODUCT_SCENE => '作品场景',
            self::MODULE_PRODUCT_TAG => '作品标签',
            self::MODULE_SYSTEM => '系统',
        ];

        return $items;
    }

    public static function getModuleText($module)
    {
        return self::getValue(self::getModuleItems(), $module, '其它');
    }

    const ACTION_ADD = 'add';
    const ACTION_UPDATE = 'update';
    const ACTION_DELETE = 'delete';
    const ACTION_DELETE_TAGS = 'delete_tags';
    const ACTION_UPDATE_SETTINGS = 'update_settings';
    const ACTION_UPLOAD = 'upload';
    const ACTION_LOGIN_SUCCESS = 'login_success';
    const ACTION_USER_LOGOUT = 'user_logout';
    const ACTION_VR_SCENE_CHUNK_PANORAMA = 'chunk_panorama';

    public static function getActionItems(): array
    {
        return [
            self::MODULE_ADMIN => [
                self::ACTION_ADD => '新增',
                self::ACTION_UPDATE => '更新',
                self::ACTION_DELETE => '删除',
                self::ACTION_UPDATE_SETTINGS => '更新配置',
                self::ACTION_UPLOAD => '上传',
                self::ACTION_LOGIN_SUCCESS => '登录成功',
                self::ACTION_USER_LOGOUT => '登出成功',
            ],
            self::MODULE_ATTACHMENT => [
                self::ACTION_UPLOAD => '上传',
            ],
            self::MODULE_PRODUCT => [
                self::ACTION_ADD => '新增',
                self::ACTION_UPDATE => '更新',
                self::ACTION_DELETE => '删除',
            ],
            self::MODULE_PRODUCT_CATALOG => [
                self::ACTION_ADD => '新增',
                self::ACTION_UPDATE => '更新',
                self::ACTION_DELETE => '删除',
            ],
            self::MODULE_PRODUCT_TAG => [
                self::ACTION_ADD => '新增',
                self::ACTION_UPDATE => '更新',
                self::ACTION_DELETE => '删除',
                self::ACTION_DELETE_TAGS => '批量删除'
            ],
            self::MODULE_PRODUCT_SCENE => [
                self::ACTION_ADD => '新增',
                self::ACTION_UPDATE => '更新',
                self::ACTION_DELETE => '删除',
                self::ACTION_VR_SCENE_CHUNK_PANORAMA => '场景图切片'
            ],
            self::MODULE_SYSTEM => [
                self::ACTION_UPDATE_SETTINGS => '更新配置'
            ],
            self::MODULE_VIP => [
                self::ACTION_ADD => '新增',
                self::ACTION_UPDATE => '更新',
                self::ACTION_DELETE => '删除',
            ]
        ];
    }

    public static function getModuleActionItems($module)
    {
        $items = self::getActionItems();

        return $items[$module] ?? [];
    }

    /**
     * @param $module
     * @param $action
     * @return mixed|string
     */
    public static function getActionText($module, $action)
    {
        $actions = self::getActionItems();
        if (!isset($actions[$module][$action])) {
            return '其它';
        }

        return $actions[$module][$action];
    }

    /**
     * @param $level
     * @return bool|int|string|null
     */
    public static function getLevelText($level)
    {
        return self::getValue(self::getLevelItems(), $level, '其它');
    }
}