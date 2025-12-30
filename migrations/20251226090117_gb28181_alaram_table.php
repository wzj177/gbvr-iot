<?php

use Phpmig\Migration\Migration;

class Gb28181AlaramTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec(<<<SQL
CREATE TABLE `gv_alarm` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `device_id` varchar(32) NOT NULL COMMENT '设备国标ID',
  `channel_id` varchar(32) DEFAULT NULL COMMENT '通道国标ID',
  `alarm_type` varchar(50) NOT NULL COMMENT '告警类型',
  `alarm_method` int(11) DEFAULT NULL COMMENT '告警方式',
  `alarm_priority` tinyint(4) NOT NULL DEFAULT '1' COMMENT '告警级别: 1=一级, 2=二级, 3=三级, 4=四级',
  `alarm_time` datetime NOT NULL COMMENT '告警时间',
  `alarm_description` text COMMENT '告警描述',
  `handled_status` enum('pending','processing','handled','ignored') NOT NULL DEFAULT 'pending' COMMENT '处理状态',
  `handled_by` varchar(100) DEFAULT NULL COMMENT '处理人',
  `handled_at` datetime DEFAULT NULL COMMENT '处理时间',
  `handle_action` varchar(255) DEFAULT NULL COMMENT '处理措施',
  `handle_remark` text COMMENT '处理备注',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_channel_id` (`channel_id`),
  KEY `idx_alarm_time` (`alarm_time`),
  KEY `idx_handled_status` (`handled_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GB28181告警表';
SQL
);
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_alarm`");
    }
}
