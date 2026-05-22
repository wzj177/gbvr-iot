
<?php

use Phpmig\Migration\Migration;

class CreateGvDevicePlaybackRecordsTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $db = $this->getContainer()['db'];

        $sql = "
            CREATE TABLE IF NOT EXISTS `gv_device_playback_records` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `device_id` varchar(64) NOT NULL COMMENT '设备ID',
                `channel_id` varchar(64) NOT NULL COMMENT '通道ID',
                `name` varchar(255) NOT NULL COMMENT '文件名称',
                `file_path` varchar(500) DEFAULT '' COMMENT '文件路径',
                `address` varchar(255) DEFAULT '' COMMENT '地址',
                `start_time` int(11) NOT NULL DEFAULT 0 COMMENT '开始时间, 秒级时间戳',
                `end_time`  int(11) NOT NULL DEFAULT 0 COMMENT '结束时间',
                `secrecy` tinyint(1) DEFAULT 0 COMMENT '保密级别 0-不保密 1-保密',
                `type` varchar(50) NOT NULL COMMENT '类型 如：time',
                `recorder_id` varchar(64) DEFAULT '' COMMENT '记录者ID',
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_device_id` (`device_id`),
                INDEX `idx_start_time` (`start_time`),
                INDEX `idx_end_time` (`end_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        $db->exec($sql);
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $db = $this->getContainer()['db'];
        $db->exec('DROP TABLE IF EXISTS `gv_device_playback_records`');
    }
}