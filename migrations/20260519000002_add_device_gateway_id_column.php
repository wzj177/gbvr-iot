<?php

use Phpmig\Migration\Migration;

class AddDeviceGatewayIdColumn extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        // Check if column already exists
        $columns = $db->fetchAll("SHOW COLUMNS FROM `gv_devices` LIKE 'gateway_id'");
        if (empty($columns)) {
            $db->exec("ALTER TABLE `gv_devices`
                ADD COLUMN `gateway_id` varchar(64) DEFAULT NULL COMMENT '绑定的SIP网关ID' AFTER `device_id`,
                ADD KEY `idx_gateway_id` (`gateway_id`)");
        }
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $columns = $db->fetchAll("SHOW COLUMNS FROM `gv_devices` LIKE 'gateway_id'");
        if (!empty($columns)) {
            $db->exec("ALTER TABLE `gv_devices`
                DROP COLUMN `gateway_id`,
                DROP KEY `idx_gateway_id`");
        }
    }
}
