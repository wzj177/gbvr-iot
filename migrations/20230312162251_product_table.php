<?php

use Phpmig\Migration\Migration;

class ProductTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `vr_product` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键id',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '作品标题',
  `cover` varchar(255) NOT NULL DEFAULT '' COMMENT '作品封面',
  `catalogId` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '作品分类',
  `recommend` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否推荐，0=否；1=是',
  `address` varchar(255) NOT NULL DEFAULT '' COMMENT '作品地址',
  `remark` text COMMENT '作品描述',
  `clickCount` int(10) NOT NULL DEFAULT '0' COMMENT '查看次数',
  `likeCount` int(10) NOT NULL DEFAULT '0' COMMENT '点赞数',
  `useIntro` tinyint(1) NOT NULL DEFAULT '0' COMMENT '开场动画；0=禁用；1=启用',
  `anonymousShow` tinyint(1) NOT NULL DEFAULT '0' COMMENT '匿名访问；0=否；1=是',
  `status` enum('published','closed','drafted') NOT NULL DEFAULT 'drafted' COMMENT '状态，published-发布 closed-关闭 drafted-草稿',
  `userId` int(10) NOT NULL DEFAULT '0' COMMENT '创建人',
  `createdTime` int(10) DEFAULT '0' COMMENT '创建时间',
  `updatedTime` int(10) NOT NULL DEFAULT '0' COMMENT '修改时间',
  `type` enum('pictures','videos','3d_ring') NOT NULL DEFAULT 'pictures' COMMENT '作品类型，pictures=图片全景；videos=视频全景；3d_ring=3d环物全景',
    `logo` varchar(255) NOT NULL DEFAULT '' COMMENT '品牌LOGO',
  `logoPosition` varchar(32) NOT NULL DEFAULT 'left_lop' COMMENT '品牌LOGO位置,左上角',
  `brandWebsite` varchar(100) NOT NULL DEFAULT '' COMMENT '品牌LOGO链接',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `vr_product`;");
    }
}
