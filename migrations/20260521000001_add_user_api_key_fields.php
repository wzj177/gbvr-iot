<?php

use Phpmig\Migration\Migration;

class AddUserApiKeyFields extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "ALTER TABLE `gv_user`
            ADD COLUMN `api_key` varchar(64) DEFAULT NULL COMMENT 'API密钥（用于OpenAPI认证）' AFTER `password`,
            ADD COLUMN `api_enabled` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否启用API访问' AFTER `api_key`,
            ADD UNIQUE KEY `uk_api_key` (`api_key`);";

        $db->exec($sql);
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "ALTER TABLE `gv_users`
            DROP COLUMN `api_key`,
            DROP COLUMN `api_enabled`;";

        $db->exec($sql);
    }
}
