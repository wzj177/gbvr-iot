<?php

use Phpmig\Migration\Migration;

class VoiceSessionAddTcpMode extends Migration
{
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $db->exec("
            ALTER TABLE `gv_voice_sessions`
            ADD COLUMN `rtp_tcp` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ZLM被动收流传输协议: 0=UDP, 1=TCP' AFTER `mode`
        ");
    }

    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $db->exec("
            ALTER TABLE `gv_voice_sessions`
            DROP COLUMN `rtp_tcp`
        ");
    }
}
