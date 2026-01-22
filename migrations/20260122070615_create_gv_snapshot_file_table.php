<?php

use Phpmig\Migration\Migration;

class CreateGvSnapshotFileTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_snapshot_file` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `device_id` VARCHAR(64) NOT NULL COMMENT 'GB28181设备ID',
  `channel_id` VARCHAR(64) NOT NULL COMMENT '通道ID',
  `channel_name` VARCHAR(255) DEFAULT NULL COMMENT '通道名称',

  `source_type` ENUM('alarm','plan','manual','playback') NOT NULL DEFAULT 'alarm' COMMENT '快照来源',
  `source_id` BIGINT(20) DEFAULT NULL COMMENT '来源ID(如 alarm_event_id / plan_id / task_id 等)',
  `source_desc` VARCHAR(255) DEFAULT NULL COMMENT '来源说明',

  `shot_time` DATETIME(3) NOT NULL COMMENT '抓拍时间(事件时间/实际抓拍时间)',
  `file_path` VARCHAR(512) DEFAULT NULL COMMENT '文件路径(本地或对象存储key)',
  `file_url` VARCHAR(512) DEFAULT NULL COMMENT '可访问URL(可为空,由后端签发/拼接)',
  `file_size` BIGINT(20) NOT NULL DEFAULT '0' COMMENT '文件大小(Byte)',

  `format` VARCHAR(16) NOT NULL DEFAULT 'jpg' COMMENT '图片格式(jpg/png/webp...)',
  `width` INT(11) DEFAULT NULL COMMENT '宽',
  `height` INT(11) DEFAULT NULL COMMENT '高',

  `media_server_id` VARCHAR(64) DEFAULT NULL COMMENT '流媒体服务器ID(如来自ZLM抓拍)',
  `media_server_ip` VARCHAR(64) DEFAULT NULL COMMENT '流媒体服务器IP',
  `vhost` VARCHAR(64) DEFAULT '__defaultVhost__' COMMENT 'vhost',
  `app` VARCHAR(32) DEFAULT NULL COMMENT '应用名',
  `stream_id` VARCHAR(64) DEFAULT NULL COMMENT '流ID',

  `asset_id` CHAR(36) DEFAULT NULL COMMENT '全局资产ID(UUID)',
  `index_status` ENUM('none','indexing','indexed','failed') NOT NULL DEFAULT 'none' COMMENT 'AI索引状态',
  `embedding_version` VARCHAR(32) DEFAULT NULL COMMENT 'embedding版本',
  `ai_meta` JSON DEFAULT NULL COMMENT 'AI元数据(标签/检测框/向量ID/摘要等)',

  `delete_at` DATETIME DEFAULT NULL COMMENT '删除时间(软删)',
  `created_at` DATETIME NOT NULL COMMENT '创建时间',
  `updated_at` DATETIME NOT NULL COMMENT '更新时间',

  PRIMARY KEY (`id`),
  KEY `idx_device_channel_time` (`device_id`,`channel_id`,`shot_time`),
  KEY `idx_source` (`source_type`,`source_id`),
  KEY `idx_stream` (`media_server_id`,`app`,`stream_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='快照文件实例';
");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec('DROP TABLE IF EXISTS `gv_snapshot_file`;');
    }
}
