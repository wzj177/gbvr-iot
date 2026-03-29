<?php

use Phpmig\Migration\Migration;

class AddDeviceCategory extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec(<<<SQL
-- 添加设备分类字段到设备表
ALTER TABLE `gv_devices`
ADD COLUMN `device_category` int(3) DEFAULT NULL COMMENT '设备分类编码（第10-13位），可手动修改覆盖自动解析值' AFTER `device_type`,
ADD INDEX `idx_device_category` (`device_category`);
SQL
        );
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec(<<<SQL
ALTER TABLE `gv_devices`
DROP INDEX `idx_device_category`,
DROP COLUMN `device_category`;
SQL
        );
    }
}
