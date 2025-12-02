<?php

use Phpmig\Migration\Migration;

class CreateVipCompanyIotTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $biz = $this->getContainer();
        $biz['db']->exec("CREATE TABLE `gv_vip_company_iot` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `userId` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '会员ID',
  `companyId` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '企业ID',
  `appId` varchar(20) NOT NULL DEFAULT '' COMMENT '应用ID',
  `appSecret` varchar(32) NOT NULL DEFAULT '' COMMENT '应用密钥',
  `serviceType` varchar(64) NOT NULL DEFAULT 'default' COMMENT '接口服务商类型',
  `host` varchar(200) NOT NULL DEFAULT '' COMMENT '物联网接口主机',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态 1=启用,0=禁用',
  `param` text COMMENT ' 其他可能参数',
  `api` text COMMENT '应用接口配置',
  `createdTime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updatedTime` int(10) NOT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_vip_company_iot`;");
    }
}
