<?php

use Phpmig\Migration\Migration;

class AddProductTourTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_product_tour` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `productId` int(10) unsigned NOT NULL DEFAULT '0',
  `title` varchar(32) NOT NULL DEFAULT '',
  `startImg` varchar(255) NOT NULL DEFAULT '',
  `endImg` varchar(255) NOT NULL DEFAULT '',
  `loopPlay` tinyint(1) NOT NULL DEFAULT '1',
  `endToStart` tinyint(1) NOT NULL DEFAULT '1',
  `txtPosition` varchar(16) NOT NULL DEFAULT 'bottom',
  `txtSize` int(3) NOT NULL DEFAULT '12',
  `createdTime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatedTime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_product_tour`");
    }
}
