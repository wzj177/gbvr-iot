<?php

use Phpmig\Migration\Migration;

class AddProductPlaneGraphTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_product_plane_graph` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `productId` int(10) unsigned NOT NULL DEFAULT '0',
  `type` enum('default','gis') NOT NULL DEFAULT 'default',
  `center` varchar(255) NOT NULL DEFAULT '',
  `rotation` varchar(64) NOT NULL DEFAULT '',
  `imgUrl` varchar(255) NOT NULL DEFAULT '',
   `gisParam` text COMMENT '地理地图配置',
  `markers` text,
  `createdTime` int(10) unsigned NOT NULL DEFAULT '0',
  `updatedTime` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='电子地图点位表';");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_product_plane_graph`;");
    }
}
