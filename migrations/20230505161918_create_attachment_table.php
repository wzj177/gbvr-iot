<?php

use Phpmig\Migration\Migration;

class CreateAttachmentTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `vr_attachment` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `globalId` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '云文件ID',
  `status` enum('uploading','ok','error') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'uploading' COMMENT '文件上传状态',
  `hashId` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '文件的HashID',
  `groupCode` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'default' COMMENT '分组',
  `filename` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '文件名',
  `newFilename` varchar(1024) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '新文件名',
  `filepath` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '文件存储路径',
  `ext` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '文件后缀',
  `fileSize` bigint(20) NOT NULL DEFAULT '0' COMMENT '文件大小',
  `length` int(11) NOT NULL DEFAULT '0' COMMENT '长度（音视频则为时长，PPT/文档为页数）',
  `etag` varchar(256) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT 'ETAG',
  `metas` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '元信息',
  `type` enum('document','video','audio','image','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other' COMMENT '文件类型',
  `storage` enum('local','qiniu','tencent','huawei') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local' COMMENT '文件存储方式',
  `createClient` enum('backend','middle','frontend') COLLATE utf8mb4_unicode_ci DEFAULT 'backend' COMMENT '创建端\r\n；backend=后台;frontend=前台；middele=中台',
  `createUserId` int(11) NOT NULL DEFAULT '0' COMMENT '创建用户',
    `imgSize` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '图片宽高',
  `videoCover` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '视频封面',
  `transcodePath` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '文件转码路径',
  `createdTime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updatedTime` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '修改时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `vr_attachment`;");
    }
}
