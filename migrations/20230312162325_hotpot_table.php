<?php

use Phpmig\Migration\Migration;

class HotpotTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_product_hotpot` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `sceneId` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '场景id',
  `productId` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '作品id',
  `userId` int(10) DEFAULT NULL COMMENT '用户id',
  `icon` varchar(32) NOT NULL DEFAULT '' COMMENT '图标值',
  `iconType` varchar(32) NOT NULL DEFAULT 'vr' COMMENT '图标类型：vr=专用图标;element=内置图标;polygon=多边形图标;polyline=线条图标;custom=自定义图标',
  `iconSize` varchar(8) NOT NULL DEFAULT 'mini' COMMENT '图标大小：big:大; medium:中等 ;mini：小型',
  `type` varchar(255) DEFAULT NULL COMMENT '热点类型：sceneChange=全景切换;hyperlink=超链接;image=图片热点;video=视频热点;text=文本热点',
  `longitude` varchar(32) NOT NULL DEFAULT '' COMMENT '经度',
  `latitude` varchar(32) NOT NULL DEFAULT '' COMMENT '纬度',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '热点标题',
  `titleFontSize` int(2) NOT NULL DEFAULT '12' COMMENT '标题字体大小',
  `titleFontColor` varchar(16) NOT NULL DEFAULT '#fff' COMMENT '标题字体颜色',
  `imgUrls` varchar(1024) DEFAULT '' COMMENT '图片热点的图片',
  `toSceneId` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '跳转场景id',
  `link` varchar(255) DEFAULT '' COMMENT '超链接',
  `linkTarget` varchar(16) NOT NULL DEFAULT '_blank' COMMENT '超链接打开方式',
  `videoUrl` varchar(255) DEFAULT '' COMMENT '视频地址',
  `content` text COMMENT '内容',
  `apiUrl` varchar(255) DEFAULT '' COMMENT '三方接口地址',
  `apiShowType` varchar(16) NOT NULL DEFAULT 'echart' COMMENT '三方数据展示方式：echart=图表；table=表格',
  `iconMarkerParams` longtext COMMENT '热点图标marke插件参数项',
  `iconTitleMarkerParams` longtext COMMENT '热点图标标题marke插件参数项',
  `createdTime` int(10) DEFAULT NULL COMMENT '创建时间',
  `updatedTime` int(10) DEFAULT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_hotpot`;");
    }
}
