<?php

use Phpmig\Migration\Migration;

class AlterRecordTaskAddFields extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("
        ALTER TABLE gv_record_task
ADD COLUMN invite_ok_time INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'INVITE成功时间' AFTER `record_duration`,
ADD COLUMN last_rtp_time INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '最后检测到RTP时间' AFTER `record_duration`;
        ");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
