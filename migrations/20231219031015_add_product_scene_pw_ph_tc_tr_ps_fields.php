<?php

use Phpmig\Migration\Migration;

class AddProductScenePwPhTcTrPsFields extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `gv_product_scene` 
ADD COLUMN `panoramaWidth` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '全景图图片宽度' AFTER `tileSize`,
ADD COLUMN `panoramaHeight` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT '全景图图片高度' AFTER `panoramaWidth`,
ADD COLUMN `tileRows` int(4) UNSIGNED NOT NULL DEFAULT 0 COMMENT '全景切片-行数' AFTER `panoramaHeight`,
ADD COLUMN `tileColumns` int(4) UNSIGNED NOT NULL DEFAULT 0 COMMENT '全景切片-列数' AFTER `tileRows`,
ADD COLUMN `panoramaSmall` varchar(255) NOT NULL DEFAULT '' COMMENT '低分辨率全景图' AFTER `tileColumns`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
