<?php

use Phpmig\Migration\Migration;

class CreateProductCatalogTagTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_product_catalog_tag` (
  `catalogId` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '分类id',
  `tagId` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '标签id',
  `createdTime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间'
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_product_catalog_tag`;");
    }
}
