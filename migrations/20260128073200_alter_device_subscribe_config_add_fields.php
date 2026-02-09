<?php

use Phpmig\Migration\Migration;

class AlterDeviceSubscribeConfigAddFields extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `gv_device_subscribe_config`
ADD COLUMN `catalog_dialog_id` INT DEFAULT NULL COMMENT '目录订阅的dialog_id' AFTER `event_mobile_position`,
ADD COLUMN `alarm_dialog_id` INT DEFAULT NULL COMMENT '报警订阅的dialog_id' AFTER `catalog_dialog_id`,
ADD COLUMN `position_dialog_id` INT DEFAULT NULL COMMENT '位置订阅的dialog_id' AFTER `alarm_dialog_id`,
ADD COLUMN `catalog_subscription_id` INT DEFAULT NULL COMMENT '目录订阅的subscription_id' AFTER `position_dialog_id`,
ADD COLUMN `alarm_subscription_id` INT DEFAULT NULL COMMENT '报警订阅的subscription_id' AFTER `catalog_subscription_id`,
ADD COLUMN `position_subscription_id` INT DEFAULT NULL COMMENT '位置订阅的subscription_id' AFTER `alarm_subscription_id`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `gv_device_subscribe_config`
DROP COLUMN `catalog_subscription_id`,
DROP COLUMN `alarm_subscription_id`,
DROP COLUMN `position_subscription_id`,
DROP COLUMN `catalog_dialog_id`,
DROP COLUMN `alarm_dialog_id`,
DROP COLUMN `position_dialog_id`;");
    }
}
