<?php

use Phpmig\Migration\Migration;

class AddProductTourNodeTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `vr_product_tour_node` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `productId` int(10) unsigned NOT NULL DEFAULT '0',
  `tourId` int(10) unsigned NOT NULL DEFAULT '0',
  `sceneId` int(10) unsigned NOT NULL DEFAULT '0',
  `idx` int(6) NOT NULL DEFAULT '1',
  `code` varchar(16) NOT NULL DEFAULT '',
  `position` varchar(100) NOT NULL DEFAULT '',
  `zoomLevel` int(10) NOT NULL DEFAULT '0',
  `waitTime` int(3) unsigned NOT NULL DEFAULT '5',
  `content` varchar(255) NOT NULL DEFAULT '',
  `voice` varchar(255) NOT NULL DEFAULT '',
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
        $container['db']->exec("DROP TABLE IF EXISTS `vr_product_tour_node`");
    }
}
