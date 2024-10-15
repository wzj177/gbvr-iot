<?php

use Phpmig\Migration\Migration;

class SceneTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `vr_product_scene` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `productId` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '作品id',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '场景标题',
  `panorama` varchar(500) NOT NULL DEFAULT '' COMMENT '全景图',
  `thumb` varchar(500) NOT NULL DEFAULT '' COMMENT '全景缩略图',
  `tilePath` varchar(500) NOT NULL DEFAULT '' COMMENT '全景瓦片生成路径',
  `tileStatus` tinyint(1) DEFAULT '0' COMMENT '瓦片生成状态，0=未生成；1=生成中；2=完成',
  `longitude` varchar(32) NOT NULL DEFAULT '0' COMMENT '经度',
  `latitude` varchar(32) NOT NULL DEFAULT '0' COMMENT '纬度',
  `minFov` float(9,6) DEFAULT '0.000000' COMMENT '最近视角：0~179',
  `maxFov` float(9,6) DEFAULT '0.000000' COMMENT '最远视角：0-179',
  `userId` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建用户',
  `desc` varchar(500) DEFAULT '' COMMENT '场景描述',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态，0=关闭；1=正常',
  `vrOptions` longtext COMMENT '前端vr插件展示所属参数项',
  `createdTime` int(10) DEFAULT NULL COMMENT '创建时间',
  `updatedTime` int(10) DEFAULT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `vr_scene`;");
    }
}
