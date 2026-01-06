<?php

use Phpmig\Migration\Migration;

class MenuTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec(<<<SQL
DROP TABLE IF EXISTS `gv_menu`;
CREATE TABLE `gv_menu` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `menuId` varchar(64) NOT NULL COMMENT '菜单唯一标识',
  `name` varchar(64) NOT NULL COMMENT '菜单名称',
  `icon` varchar(64) DEFAULT '' COMMENT '图标',
  `path` varchar(255) DEFAULT '' COMMENT '前端路径/API路径',
  `component` varchar(128) DEFAULT '' COMMENT '前端组件名',
  `parentId` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '父级菜单ID',
  `parentMenuId` varchar(64) DEFAULT '' COMMENT '父级菜单标识',
  `sort` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '排序',
  `type` enum('menu','directory','path','api') NOT NULL DEFAULT 'menu' COMMENT '类型：menu=菜单页，directory=目录，path=路径页，api=API',
  `httpMethod` varchar(16) DEFAULT '' COMMENT 'HTTP方法（API类型使用，如GET,POST,PUT,DELETE）',
  `routeName` varchar(128) DEFAULT '' COMMENT '路由名称',
  `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态：0=禁用，1=启用',
  `createdTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  `updatedTime` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `menuId` (`menuId`),
  KEY `parentId` (`parentId`),
  KEY `type` (`type`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='菜单权限表';
SQL
        );
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec('DROP TABLE IF EXISTS `gv_menu`');
    }
}
