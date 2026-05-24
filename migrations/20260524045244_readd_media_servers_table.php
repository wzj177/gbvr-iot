<?php

use Phpmig\Migration\Migration;

class ReaddMediaServersTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];
        // 删除 table
        $sql = "DROP TABLE IF EXISTS `gv_media_servers`;";

        $sql = "CREATE TABLE `gv_media_servers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `server_id` varchar(32) NOT NULL COMMENT '服务器唯一标识',
  `name` varchar(100) NOT NULL COMMENT '服务器名称',
  `type` varchar(20) NOT NULL DEFAULT 'zlm' COMMENT '流媒体类型: zlm, srs, other',
  `support_gb28181` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否支持GB28181协议（0=不支持，1=支持）',
  `host` varchar(255) NOT NULL COMMENT '服务器IP地址',
  `port` int(11) NOT NULL DEFAULT '80' COMMENT 'HTTP API端口',
  `https_port` int(11) NOT NULL DEFAULT '443' COMMENT 'https 端口号',
  `secret` varchar(100) NOT NULL DEFAULT '' COMMENT 'API密钥',
  `access_domain` varchar(500) DEFAULT '' COMMENT '访问地址（nginx反向代理地址，用于播放地址返回）',
  `network_env` enum('internal','public') NOT NULL DEFAULT 'internal' COMMENT '网络环境: internal=内网, public=公网',
  `status` enum('running','stopped','unknown','offline') NOT NULL DEFAULT 'unknown' COMMENT '运行状态',
  `cpu_usage` decimal(5,2) DEFAULT '0.00' COMMENT 'CPU使用率(缓存)',
  `memory_usage` decimal(5,2) DEFAULT '0.00' COMMENT '内存使用率(缓存)',
  `disk_usage` decimal(5,2) DEFAULT '0.00' COMMENT '磁盘使用率(缓存)',
  `stream_count` int(11) DEFAULT '0' COMMENT '流数量(缓存)',
  `player_count` int(11) DEFAULT '0' COMMENT '播放器数量(缓存)',
  `network_in` bigint(20) DEFAULT '0' COMMENT '网络入流量(字节,缓存)',
  `network_out` bigint(20) DEFAULT '0' COMMENT '网络出流量(字节,缓存)',
  `uptime` int(11) DEFAULT '0' COMMENT '运行时长(秒,缓存)',
  `last_sync_at` datetime DEFAULT NULL COMMENT '最后同步时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  `stream_ip` varchar(255) NOT NULL DEFAULT '' COMMENT '收流IP（用于SDP，为空则使用host)',
  `record_path` varchar(255) DEFAULT NULL COMMENT '录像存储目录',
  `last_heartbeat_time` datetime DEFAULT NULL COMMENT '最后心跳时间',
  `send_rtp_port_range` varchar(64) NOT NULL DEFAULT '' COMMENT 'rtp发送端口范围',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_server_id` (`server_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`),
  KEY `idx_network_env` (`network_env`),
  KEY `idx_created_at` (`created_at`) USING BTREE,
  KEY `idx_support_gb28181` (`support_gb28181`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='GB28181流媒体服务器表';";

        $db->exec($sql);
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
