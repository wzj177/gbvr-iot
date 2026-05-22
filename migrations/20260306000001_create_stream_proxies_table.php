<?php

use Phpmig\Migration\Migration;

class CreateStreamProxiesTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "CREATE TABLE IF NOT EXISTS `gv_stream_proxies` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
            `proxy_id` varchar(64) NOT NULL COMMENT '流代理ID（UUID）',
            `name` varchar(100) NOT NULL COMMENT '代理名称',
            `type` enum('pull','push') NOT NULL DEFAULT 'pull' COMMENT '代理类型',
            `protocol` varchar(20) NOT NULL COMMENT '协议类型：rtsp/rtmp/http-flv',

            -- 源地址（拉流时使用）
            `source_url` varchar(500) DEFAULT NULL COMMENT '源地址',

            -- ZLM流映射
            `app` varchar(64) NOT NULL DEFAULT 'proxy' COMMENT 'ZLM应用名',
            `stream` varchar(100) NOT NULL COMMENT 'ZLM流ID（UUID）',
            `vhost` varchar(64) NOT NULL DEFAULT '__defaultVhost__' COMMENT 'ZLM虚拟主机',

            -- 流媒体服务器
            `media_server_id` varchar(64) NOT NULL COMMENT '流媒体服务器ID',

            -- 状态管理（类似设备）
            `status` enum('online','offline','stopped','error') NOT NULL DEFAULT 'stopped' COMMENT '状态',
            `last_heartbeat_at` datetime DEFAULT NULL COMMENT '最后心跳时间',
            `error_message` text DEFAULT NULL COMMENT '错误信息',

            -- 录像计划关联
            `record_plan_id` int(10) NOT NULL DEFAULT '0' COMMENT '录像计划ID（0=未绑定）',
            `record_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '录像状态（0=未录像，1=录像中）',

            -- 拉流配置
            `enable_auto_reconnect` tinyint(1) NOT NULL DEFAULT '1' COMMENT '启用自动重连',
            `max_retry_count` int(11) NOT NULL DEFAULT '10' COMMENT '最大重试次数',
            `current_retry_count` int(11) NOT NULL DEFAULT '0' COMMENT '当前重试次数',
            `timeout_sec` int(11) NOT NULL DEFAULT '10' COMMENT '超时时间（秒）',
            `rtp_type` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'RTSP拉流：0=TCP,1=UDP',

            -- 协议转换配置
            `enable_hls` tinyint(1) NOT NULL DEFAULT '1' COMMENT '启用HLS',
            `enable_mp4` tinyint(1) NOT NULL DEFAULT '0' COMMENT '启用MP4',

            -- 统计信息
            `viewer_count` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '观看人数',
            `total_start_count` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '总启动次数',
            `total_reconnect_count` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '总重连次数',

            -- 元数据
            `description` varchar(500) DEFAULT NULL COMMENT '描述',
            `tags` json DEFAULT NULL COMMENT '标签',
            `zlm_key` varchar(100) DEFAULT NULL COMMENT 'ZLM代理key',

            -- 时间戳
            `started_at` datetime DEFAULT NULL COMMENT '启动时间',
            `stopped_at` datetime DEFAULT NULL COMMENT '停止时间',
            `created_at` datetime NOT NULL COMMENT '创建时间',
            `updated_at` datetime NOT NULL COMMENT '更新时间',

            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_proxy_id` (`proxy_id`),
            UNIQUE KEY `uk_app_stream` (`app`,`stream`,`media_server_id`),
            KEY `idx_status` (`status`),
            KEY `idx_type` (`type`),
            KEY `idx_media_server` (`media_server_id`),
            KEY `idx_record_plan` (`record_plan_id`),
            KEY `idx_record_status` (`record_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='流代理表';";

        $db->exec($sql);
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "DROP TABLE IF EXISTS `gv_stream_proxies`;";
        $db->exec($sql);
    }
}
