<?php

use Phpmig\Migration\Migration;

class AddProductHotpotFields extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `vr_product_hotpot` 
ADD COLUMN `uuid` varchar(32) NOT NULL DEFAULT '' COMMENT '唯一编号' AFTER `id`,
ADD UNIQUE INDEX `uuid_unqiue_idx`(`uuid`);");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
