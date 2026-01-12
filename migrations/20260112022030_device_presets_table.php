<?php

use Phpmig\Migration\Migration;

class DevicePresetsTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE `gv_device_presets` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `device_id` varchar(64) NOT NULL DEFAULT '' COMMENT '设备ID',
  `channel_id` varchar(64) NOT NULL DEFAULT '' COMMENT '通道ID',
  `value` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '预置位编号(1-255)',
  `status` varchar(32) NOT NULL DEFAULT 'unset' COMMENT '状态: unset=未设置, setting=设置中, set=已设置',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '预置位名称',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_device_channel_value` (`device_id`, `channel_id`, `value`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_channel_id` (`channel_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='国标设备预置位表';");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_device_presets`;");
    }
}
