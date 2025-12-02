<?php

use Phpmig\Migration\Migration;

class LogAddSideField extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `gv_log` 
ADD COLUMN `side` varchar(32) NOT NULL DEFAULT '' COMMENT '业务端,admin=后台,vip=会员' AFTER `ipArea`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
