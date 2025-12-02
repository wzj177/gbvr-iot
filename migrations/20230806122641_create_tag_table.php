<?php

use Phpmig\Migration\Migration;

class CreateTagTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_tag` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `type` enum('system','custom') DEFAULT 'system' COMMENT '类型 system=系统标签 ;custom=自定义生成',
  `name` varchar(100) NOT NULL COMMENT '标签名称',
  `userId` int(10) NOT NULL DEFAULT '0' COMMENT '用户id，type为system时，为系统管理用户id，custom时为会员端自定义生成',
  `createdTime` int(10) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updatedTime` int(10) NOT NULL DEFAULT '0' COMMENT '修改时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_name_user_uidx` (`type`,`name`,`userId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_tag`;");
    }
}
