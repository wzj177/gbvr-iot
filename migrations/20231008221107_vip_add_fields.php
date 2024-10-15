<?php

use Phpmig\Migration\Migration;

class VipAddFields extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `vr_vip` 
    ADD COLUMN `phone` varchar(16) NOT NULL DEFAULT '' COMMENT '手机号' AFTER `inviteCode`,
ADD COLUMN `status` tinyint(1) NOT NULL COMMENT ' 状态，0=封禁，1=正常' AFTER `loginTime`,
ADD COLUMN `integral` int(10) NOT NULL DEFAULT 0 COMMENT '积分' AFTER `status`,
ADD COLUMN `spaceSize` int(10) NOT NULL DEFAULT 0 COMMENT '空间大小，单位：字节B' AFTER `integral`,
ADD COLUMN `usedSpaceSize` int(10) NOT NULL DEFAULT 0 COMMENT '已使用空间大小，单位：字节b' AFTER `spaceSize`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
