<?php

use Phpmig\Migration\Migration;

class AddProductSceneField extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `vr_product_scene` 
ADD COLUMN `number` int(10) NOT NULL DEFAULT 0 COMMENT '序号' AFTER `id`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
