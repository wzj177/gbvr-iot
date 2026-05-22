<?php

use Phpmig\Migration\Migration;

class MediaServerAddStreamIp extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `gv_media_servers` ADD COLUMN `stream_ip` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '收流IP（用于SDP，为空则使用host)'");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
