<?php

use Phpmig\Migration\Migration;

class CreateAlarmPlanChannelTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_alarm_plan_channel` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alarm_plan_id` BIGINT(20) NOT NULL COMMENT '报警预案ID',
  `device_id` VARCHAR(64) NOT NULL,
  `channel_id` VARCHAR(64) NOT NULL,
  `enabled` TINYINT(1) NOT NULL DEFAULT '1',

  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_plan_device_channel` (`alarm_plan_id`,`device_id`,`channel_id`),
  KEY `idx_device_channel` (`device_id`,`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='报警预案-通道关联';");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec('DROP TABLE IF EXISTS `gv_alarm_plan_channel`');
    }
}
