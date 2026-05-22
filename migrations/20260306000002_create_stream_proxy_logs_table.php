<?php

use Phpmig\Migration\Migration;

class CreateStreamProxyLogsTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "
        CREATE TABLE IF NOT EXISTS `gv_stream_proxy_logs` (
          `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
          `proxy_id` varchar(64) NOT NULL COMMENT '流代理ID',
          `event_type` varchar(20) NOT NULL COMMENT '事件类型：created,started,stopped,online,offline,error,reconnect_attempt,reconnect_success,reconnect_failed,deleted',
          `level` varchar(10) NOT NULL DEFAULT 'info' COMMENT '日志级别：debug,info,warning,error',
          `message` varchar(500) NOT NULL COMMENT '日志消息',
          `details` json DEFAULT NULL COMMENT '详细信息',
          `user_id` int(11) DEFAULT NULL COMMENT '操作用户ID（如果是用户操作）',
          `ip_address` varchar(45) DEFAULT NULL COMMENT '操作IP地址',
          `created_at` datetime NOT NULL COMMENT '创建时间',
          PRIMARY KEY (`id`),
          KEY `idx_proxy_id` (`proxy_id`),
          KEY `idx_event_type` (`event_type`),
          KEY `idx_level` (`level`),
          KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='流代理日志表';
        ";

        $db->exec($sql);
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "DROP TABLE IF EXISTS `gv_stream_proxy_logs`";
        $db->exec($sql);
    }
}
