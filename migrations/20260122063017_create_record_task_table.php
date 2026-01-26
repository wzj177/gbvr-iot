<?php

use Phpmig\Migration\Migration;

class CreateRecordTaskTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_record_task` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `partner_id` int(11) NOT NULL DEFAULT '0',
  `task_type` enum('plan','alarm','playback_download') NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `channel_id` varchar(64) NOT NULL,
  `media_server_id` varchar(64) DEFAULT NULL,
  `vhost` varchar(64) DEFAULT '__defaultVhost__',
  `app` varchar(32) DEFAULT NULL,
  `stream_id` varchar(64) NOT NULL,
  `ssrc` varchar(32) NOT NULL,
  `dialog_id` bigint(20) DEFAULT NULL COMMENT '回放会话用',
  `start_time` int(11) NOT NULL DEFAULT '0' COMMENT '开始时间(时间戳)',
  `end_time` int(11) NOT NULL DEFAULT '0' COMMENT '结束时间(时间戳)',
  `customized_path` varchar(255) DEFAULT NULL COMMENT '自定义路径',
  `download_speed` float(4,1) unsigned NOT NULL DEFAULT '1.0',
  `status` enum('pending','inviting','wait_stream','recording','finalizing','done','failed','cancelled') NOT NULL DEFAULT 'pending',
  `fail_reason` varchar(255) DEFAULT NULL,
  `record_start_time` int(11) unsigned DEFAULT NULL COMMENT '录像开始时间(时间戳)',
  `record_end_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '录像结束时间(时间戳)',
  `record_duration` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '录像时长(秒)',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_task_type_time` (`task_type`,`start_time`),
  KEY `idx_device_channel` (`device_id`,`channel_id`),
  KEY `idex_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='录像生成任务';
");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
