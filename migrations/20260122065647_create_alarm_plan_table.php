<?php

use Phpmig\Migration\Migration;

class CreateAlarmPlanTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_alarm_plan` (
                                      `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '主键ID',
                                      `name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '报警预案名称',
                                      `status` TINYINT(1) NOT NULL DEFAULT '0' COMMENT '是否启用(1=启用；0=禁用)',
                                      `remark` TEXT COMMENT '描述',
                                      `snapshot_interval_sec` INT(11) NOT NULL DEFAULT '0' COMMENT '快照周期(秒),0表示不抓拍',
                                      `record_duration_sec` INT(11) NOT NULL DEFAULT '0' COMMENT '录像时长(秒),0表示不录像',
                                      `alarm_level` JSON DEFAULT NULL COMMENT '报警级别匹配规则',
                                      `alarm_method` JSON DEFAULT NULL COMMENT '报警方式匹配规则',
                                      `alarm_type` JSON DEFAULT NULL COMMENT '报警类型匹配规则',
                                      `alarm_eventtype` JSON DEFAULT NULL COMMENT '事件类型匹配规则',
                                      `created_at` DATETIME NOT NULL,
                                      `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='报警预案(极简版)';
");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec('DROP TABLE IF EXISTS `gv_alarm_plan`');
    }
}
