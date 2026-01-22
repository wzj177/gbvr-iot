<?php

use Phpmig\Migration\Migration;

class CreateAlarmEventTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_alarm_event` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,

  `device_id` VARCHAR(64) NOT NULL,
  `channel_id` VARCHAR(64) NOT NULL,

  `level` TINYINT(1) NOT NULL COMMENT '报警级别1-4',
  `method` TINYINT(2) NOT NULL COMMENT '报警方式1-7',
  `type` SMALLINT(5) DEFAULT NULL COMMENT '报警类型(依method解释)',
  `eventtype` TINYINT(1) DEFAULT NULL COMMENT '入侵事件类型1进入2离开',

  `description` VARCHAR(512) DEFAULT NULL,
  `longitude` DECIMAL(10,6) DEFAULT NULL,
  `latitude` DECIMAL(10,6) DEFAULT NULL,

  `alarm_time` DATETIME(3) NOT NULL COMMENT '报警发生时间',
  `recv_time` DATETIME(3) NOT NULL COMMENT '平台接收时间',

  `alarm_plan_id` BIGINT(20) DEFAULT NULL COMMENT '命中的报警预案(可为空)',

  `raw_payload` TEXT COMMENT '原始报文',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_device_channel_time` (`device_id`,`channel_id`,`alarm_time`),
  KEY `idx_plan` (`alarm_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='报警事件';
");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec('DROP TABLE IF EXISTS `gv_alarm_event`');
    }
}
