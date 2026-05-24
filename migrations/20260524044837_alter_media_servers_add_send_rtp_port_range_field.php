<?php

use Phpmig\Migration\Migration;

class AlterMediaServersAddSendRtpPortRangeField extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "ALTER TABLE `gv_media_servers`
            ADD COLUMN `send_rtp_port_range` varchar(64) NOT NULL DEFAULT '' COMMENT 'rtp发送端口范围';";

        $db->exec($sql);
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
