<?php

use Phpmig\Migration\Migration;

class AddMediaServerGb28181Support extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec(<<<SQL
-- 添加国标支持字段到流媒体服务器表
ALTER TABLE `gv_media_servers`
ADD COLUMN `support_gb28181` tinyint(1) NOT NULL DEFAULT 1 COMMENT '是否支持GB28181协议（0=不支持，1=支持）' AFTER `type`,
ADD INDEX `idx_support_gb28181` (`support_gb28181`);
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
ALTER TABLE `gv_media_servers`
DROP INDEX `idx_support_gb28181`,
DROP COLUMN `support_gb28181`;
SQL
        );
    }
}
