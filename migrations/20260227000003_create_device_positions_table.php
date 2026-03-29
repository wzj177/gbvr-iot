<?php

use Phpmig\Migration\Migration;

class CreateDevicePositionsTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "CREATE TABLE IF NOT EXISTS `gv_device_positions` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '位置记录ID',
            `partner_id` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '合作方ID',
            `device_id` varchar(64) NOT NULL COMMENT '设备ID（GB28181设备编码）',
            `cmd_type` varchar(32) NOT NULL DEFAULT 'MobilePosition' COMMENT '命令类型',
            `time` int(10) unsigned NOT NULL COMMENT '设备上报时间（时间戳）',
            `longitude` decimal(10, 6) NOT NULL DEFAULT 0 COMMENT '经度',
            `latitude` decimal(10, 6) NOT NULL DEFAULT 0 COMMENT '纬度',
            `speed` decimal(8, 2) NOT NULL DEFAULT 0 COMMENT '速度（km/h）',
            `direction` int(10) unsigned NOT NULL DEFAULT 0 COMMENT '方向（0-359度）',
            `altitude` decimal(8, 2) NOT NULL DEFAULT 0 COMMENT '海拔高度（米）',
            `recv_time` int(10) unsigned NOT NULL COMMENT '平台接收时间（时间戳）',
            `raw_data` text DEFAULT NULL COMMENT '原始数据（JSON）',
            PRIMARY KEY (`id`),
            KEY `idx_device_id` (`device_id`),
            KEY `idx_time` (`time`),
            KEY `idx_device_time` (`device_id`, `time`),
            KEY `idx_partner_id` (`partner_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='设备位置信息表（移动位置订阅）';";

        $db->exec($sql);
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "DROP TABLE IF EXISTS `gv_device_positions`;";
        $db->exec($sql);
    }
}
