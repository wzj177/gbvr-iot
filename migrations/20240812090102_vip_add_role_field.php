<?php

use Phpmig\Migration\Migration;

class VipAddRoleField extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `gv_vip` 
ADD COLUMN `role` tinyint(0) UNSIGNED NOT NULL DEFAULT 0 COMMENT '会员角色,0=个人,1=企业' AFTER `destroyed`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
