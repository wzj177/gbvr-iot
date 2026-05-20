<?php

use Phpmig\Migration\Migration;

class CreateRecordMergeTasksTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "CREATE TABLE IF NOT EXISTS `gv_record_merge_tasks` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `device_id` varchar(64) NOT NULL DEFAULT '' COMMENT 'GB28181设备ID',
            `channel_id` varchar(64) NOT NULL DEFAULT '' COMMENT 'GB28181通道ID',
            `start_time` int(11) unsigned NOT NULL COMMENT '合并开始时间(时间戳)',
            `end_time` int(11) NOT NULL COMMENT '合并结束时间(时间戳)',
            `source_file_ids` text COMMENT '源录像文件ID列表(JSON数组)',
            `source_file_count` int(11) NOT NULL DEFAULT 0 COMMENT '源文件数量',
            `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT '状态: pending/merging/done/failed',
            `output_path` varchar(500) DEFAULT '' COMMENT '合并后文件路径',
            `output_file_size` bigint(20) DEFAULT 0 COMMENT '合并后文件大小(字节)',
            `output_duration` int(11) DEFAULT 0 COMMENT '合并后时长(秒)',
            `error_message` varchar(500) DEFAULT '' COMMENT '失败原因',
            `started_at` datetime DEFAULT NULL COMMENT '开始合并时间',
            `finished_at` datetime DEFAULT NULL COMMENT '合并完成时间',
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_device_channel` (`device_id`, `channel_id`),
            KEY `idx_status` (`status`),
            KEY `idx_time_range` (`device_id`, `channel_id`, `start_time`, `end_time`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='录像合并任务表';";

        $db->exec($sql);
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "DROP TABLE IF EXISTS `gv_record_merge_tasks`;";
        $db->exec($sql);
    }
}
