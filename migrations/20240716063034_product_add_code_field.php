<?php

use Phpmig\Migration\Migration;

class ProductAddCodeField extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `gv_product` 
ADD COLUMN `code` varchar(36) NOT NULL DEFAULT '' COMMENT '唯一编码' AFTER `id`,
ADD UNIQUE INDEX `idx_code_unique`(`code`);");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
