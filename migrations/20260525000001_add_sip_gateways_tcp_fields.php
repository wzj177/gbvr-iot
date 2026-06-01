<?php

use Phpmig\Migration\Migration;

class AddSipGatewaysTcpFields extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $db->exec("ALTER TABLE `gv_sip_gateways`
            ADD COLUMN `tcp_pid` int(11) DEFAULT NULL COMMENT 'TCP进程PID' AFTER `pid`,
            ADD COLUMN `tcp_status` varchar(20) DEFAULT 'inactive' COMMENT 'TCP状态: active/inactive' AFTER `tcp_pid`
        ");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $db->exec("ALTER TABLE `gv_sip_gateways`
            DROP COLUMN `tcp_pid`,
            DROP COLUMN `tcp_status`
        ");
    }
}
