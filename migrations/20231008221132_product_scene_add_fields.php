<?php

use Phpmig\Migration\Migration;

class ProductSceneAddFields extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `vr_product_scene` 
ADD COLUMN `panoramaSize` int(10) NOT NULL DEFAULT 0 COMMENT '全景图文件大小，单位：字节' AFTER `updatedTime`,
ADD COLUMN `thumbSize` int(10) NOT NULL DEFAULT 0 COMMENT '缩略图文件大小，单位：字节' AFTER `panoramaSize`,
ADD COLUMN `tileSize` int(10) NOT NULL DEFAULT 0 COMMENT '全景瓦片文件总大小，单位：字节' AFTER `thumbSize`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
