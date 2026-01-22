<?php

use Phpmig\Migration\Migration;

class CreateAlarmEventAssetTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_alarm_event_asset` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alarm_event_id` BIGINT(20) NOT NULL COMMENT '报警事件ID',

  `asset_type` ENUM('snapshot','record_file') NOT NULL COMMENT '资源类型',
  `asset_id` BIGINT(20) NOT NULL COMMENT '资源ID: snapshot表主键 或 gv_record_file.id',

  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),

  KEY `idx_event` (`alarm_event_id`),
  KEY `idx_asset` (`asset_type`,`asset_id`),
  UNIQUE KEY `uk_event_asset` (`alarm_event_id`,`asset_type`,`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='报警事件关联的快照/录像资源';
");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec('DROP TABLE IF EXISTS `gv_alarm_event_asset`');
    }
}
