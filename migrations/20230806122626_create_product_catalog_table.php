<?php

use Phpmig\Migration\Migration;

class CreateProductCatalogTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_product_catalog` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `parentId` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '父级ID',
  `icon` varchar(50) NOT NULL DEFAULT '' COMMENT '图标',
  `name` varchar(100) NOT NULL COMMENT '名称',
  `code` varchar(32) NOT NULL DEFAULT '' COMMENT '唯一编码',
   `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态，0=禁用，1=正常',
  `path` varchar(100) NOT NULL DEFAULT '' COMMENT '分类完整路径',
  `remark` varchar(255) NOT NULL DEFAULT '' COMMENT '备注',
  `cover` varchar(150) NOT NULL DEFAULT '' COMMENT '封面图',
  `sort` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '排序',
  `createdTime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updatedTime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '修改时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_unique_idx` (`name`),
  UNIQUE KEY `code_unique_idx` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_product_catalog`;");
    }
}
