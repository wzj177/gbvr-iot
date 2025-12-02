<?php

use Phpmig\Migration\Migration;

class CreateVipCompanyTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $biz = $this->getContainer();
        $biz['db']->exec("CREATE TABLE `gv_vip_company` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `userId` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '会员ID',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '企业名称',
  `code` varchar(64) NOT NULL DEFAULT '' COMMENT '企业信用代码',
  `logo` varchar(255) NOT NULL DEFAULT '' COMMENT '企业Logo',
  `contactName` varchar(100) NOT NULL DEFAULT '' COMMENT '联系人',
  `contactMobile` varchar(20) NOT NULL DEFAULT '' COMMENT '联系人手机',
  `contactEmail` varchar(100) NOT NULL DEFAULT '' COMMENT '联系人邮箱',
  `license` varchar(255) NOT NULL COMMENT '营业执照',
  `status` int(2) NOT NULL DEFAULT '0' COMMENT '状态, -1=未通过,0=待审核,1=已通过',
  `reason` varchar(255) NOT NULL DEFAULT '' COMMENT '拒绝原因',
  `createdTime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updatedTime` int(10) NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_vip_company`;");
    }
}
