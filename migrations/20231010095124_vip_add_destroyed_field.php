<?php

use Phpmig\Migration\Migration;

class VipAddDestroyedField extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `vr_vip` 
ADD COLUMN `destroyed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否注销，0=否，1=是' AFTER `usedSpaceSize`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
