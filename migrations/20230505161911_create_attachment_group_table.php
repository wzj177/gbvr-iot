<?php

use Phpmig\Migration\Migration;

class CreateAttachmentGroupTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `vr_attachment_group` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `code` char(32) NOT NULL COMMENT '分组编码',
  `title` varchar(255) NOT NULL COMMENT '分组标题',
  `isDefault` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '是否为系统默认分组',
  `sort` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '排序',
  `parentId` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '上级ID',
  `level` int(4) NOT NULL DEFAULT '1' COMMENT '级别',
  `createdTime` int(10) unsigned DEFAULT '0' COMMENT '创建时间',
  `updatedTime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '修改时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_code` (`code`),
  UNIQUE KEY `idx_title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
        $container['db']->exec("INSERT INTO `vr_attachment_group`(`id`, `code`, `title`, `isDefault`, `sort`, `createdTime`, `updatedTime`) VALUES (1, 'default', '默认分组', 1, 0, 1683274689, 1683274689);
INSERT INTO `vr_attachment_group`(`id`, `code`, `title`, `isDefault`, `sort`, `createdTime`, `updatedTime`) VALUES (2, 'product', '作品', 1, 1, 1683274689, 1683274689);
INSERT INTO `vr_attachment_group`(`id`, `code`, `title`, `isDefault`, `sort`, `createdTime`, `updatedTime`) VALUES (3, 'scene', '场景', 1, 2, 1683274689, 1683274689);
INSERT INTO `vr_attachment_group`(`id`, `code`, `title`, `isDefault`, `sort`, `createdTime`, `updatedTime`) VALUES (4, 'vip', '会员', 1, 3, 1683274689, 1683274689);
");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `vr_attachment_group`;");
    }
}
