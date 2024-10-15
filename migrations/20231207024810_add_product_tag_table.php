<?php

use Phpmig\Migration\Migration;

class AddProductTagTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `vr_product_tag` (
  `productId` int(10) unsigned NOT NULL DEFAULT '0',
  `userId` int(10) unsigned NOT NULL DEFAULT '0',
  `tagId` int(10) unsigned NOT NULL DEFAULT '0',
  `tagType` varchar(16) NOT NULL DEFAULT '',
  `tagName` varchar(100) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `vr_product_tag`;");
    }
}
