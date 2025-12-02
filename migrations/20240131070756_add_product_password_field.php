<?php

use Phpmig\Migration\Migration;

class AddProductPasswordField extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `gv_product` 
ADD COLUMN `password` varchar(20) NOT NULL DEFAULT '' COMMENT '访问密码' AFTER `brandWebsite`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
