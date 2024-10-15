<?php

use Phpmig\Migration\Migration;

class VipInviteCodeTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `vr_vip_invite_code` (
  `int` int(11) NOT NULL AUTO_INCREMENT COMMENT '主键id',
  `code` varchar(64) NOT NULL,
  `expireTime` int(10) NOT NULL DEFAULT '0' COMMENT '注册码有效期，0=长期有效 非0=到期时间戳',
  `status` tinyint(1) NOT NULL COMMENT '使用状态，0=未使用1;=已使用',
  `bindUserId` int(10) NOT NULL DEFAULT '0' COMMENT '绑定的用户id',
  `createdTime` int(10) NOT NULL COMMENT '创建时间',
  `updatedTime` int(10) NOT NULL COMMENT '修改时间',
  PRIMARY KEY (`int`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
