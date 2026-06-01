<?php

use Phpmig\Migration\Migration;

class AlterMergeTaskRecordFields extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "ALTER TABLE `gv_record_merge_tasks`
                ADD COLUMN `media_server_id` int(11) NOT NULL DEFAULT 0 COMMENT '媒体服务器ID' AFTER `channel_id`,
                ADD KEY `idx_media_server_id` (`media_server_id`);";

        $db->exec($sql);
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "ALTER TABLE `gv_record_merge_tasks` DROP COLUMN `media_server_id`;";
        $db->exec($sql);
    }
}
