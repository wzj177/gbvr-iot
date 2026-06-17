<?php

use Phpmig\Migration\Migration;

class AddStreamSessionsUniqueConstraint extends Migration
{
    /**
     * 添加 gv_stream_sessions 表的唯一约束
     * 用于乐观锁防止并发创建重复会话
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        // 添加 stream_id + type 唯一约束
        $db->executeStatement(
            "ALTER TABLE `gv_stream_sessions` ADD UNIQUE KEY `uk_stream_type` (`stream_id`, `type`)"
        );
    }

    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        // 删除唯一约束
        $db->executeStatement(
            "ALTER TABLE `gv_stream_sessions` DROP KEY `uk_stream_type`"
        );
    }
}
