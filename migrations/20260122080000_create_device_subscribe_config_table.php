<?php

use Phpmig\Migration\Migration;

class CreateDeviceSubscribeConfigTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_device_subscribe_config` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id` VARCHAR(64) NOT NULL COMMENT '设备ID',
  `channel_id` VARCHAR(64) DEFAULT NULL COMMENT '通道ID(NULL=设备级订阅)',

  `event_catalog` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否订阅目录变更(1=是;0=否)',
  `event_alarm` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否订阅报警(1=是;0=否)',
  `event_mobile_position` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否订阅移动位置(1=是;0=否)',

  `alarm_priority_min` TINYINT DEFAULT 0 COMMENT '报警订阅最低优先级(0-4)',
  `alarm_priority_max` TINYINT DEFAULT 4 COMMENT '报警订阅最高优先级(0-4)',
  `mobile_interval_sec` INT DEFAULT 5 COMMENT '移动位置上报间隔(秒)',

  `subscribe_expires` INT DEFAULT 3600 COMMENT '订阅有效期(秒)',
  `auto_renew` TINYINT(1) DEFAULT 1 COMMENT '是否自动续订(1=是;0=否)',

  `status` TINYINT(1) DEFAULT 1 COMMENT '状态(1=启用;0=禁用)',
  `last_subscribed_at` DATETIME DEFAULT NULL COMMENT '最后订阅时间',
  `subscription_expires_at` DATETIME DEFAULT NULL COMMENT '订阅过期时间',

  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_channel` (`device_id`, `channel_id`),
  KEY `idx_status` (`status`, `auto_renew`),
  KEY `idx_expires_at` (`subscription_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='GB28181设备订阅配置';");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec('DROP TABLE IF EXISTS `gv_device_subscribe_config`');
    }
}
