<?php

use Phpmig\Migration\Migration;

class MediaServerAddAccessUrl extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec(<<<SQL
-- 添加新字段
ALTER TABLE `gv_media_servers` ADD COLUMN `access_url` varchar(500) DEFAULT '' COMMENT '访问地址（nginx反向代理地址，用于播放地址返回）' AFTER `secret`;
ALTER TABLE `gv_media_servers` ADD COLUMN `network_env` enum('internal','public') NOT NULL DEFAULT 'internal' COMMENT '网络环境: internal=内网, public=公网' AFTER `access_url`;

-- 删除不需要的字段
ALTER TABLE `gv_media_servers` DROP COLUMN `hook_url`;
ALTER TABLE `gv_media_servers` DROP COLUMN `http_port`;
ALTER TABLE `gv_media_servers` DROP COLUMN `https_port`;
ALTER TABLE `gv_media_servers` DROP COLUMN `rtsp_port`;
ALTER TABLE `gv_media_servers` DROP COLUMN `rtsp_ssl_port`;
ALTER TABLE `gv_media_servers` DROP COLUMN `rtmp_port`;
ALTER TABLE `gv_media_servers` DROP COLUMN `rtmp_ssl_port`;
ALTER TABLE `gv_media_servers` DROP COLUMN `rtc_port`;
ALTER TABLE `gv_media_servers` DROP COLUMN `rtp_port_range`;
ALTER TABLE `gv_media_servers` DROP COLUMN `default_snap`;

-- 添加索引
ALTER TABLE `gv_media_servers` ADD INDEX `idx_network_env` (`network_env`);
SQL);
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec(<<<SQL
-- 回滚：删除新字段
ALTER TABLE `gv_media_servers` DROP COLUMN `access_url`;
ALTER TABLE `gv_media_servers` DROP COLUMN `network_env`;
ALTER TABLE `gv_media_servers` DROP INDEX `idx_network_env`;

-- 回滚：添加旧字段
ALTER TABLE `gv_media_servers` ADD COLUMN `hook_url` varchar(500) DEFAULT '' COMMENT 'HOOK回调地址' AFTER `secret`;
ALTER TABLE `gv_media_servers` ADD COLUMN `http_port` int(11) DEFAULT NULL COMMENT 'HTTP端口' AFTER `hook_url`;
ALTER TABLE `gv_media_servers` ADD COLUMN `https_port` int(11) DEFAULT NULL COMMENT 'HTTPS端口' AFTER `http_port`;
ALTER TABLE `gv_media_servers` ADD COLUMN `rtsp_port` int(11) DEFAULT NULL COMMENT 'RTSP端口' AFTER `https_port`;
ALTER TABLE `gv_media_servers` ADD COLUMN `rtsp_ssl_port` int(11) DEFAULT NULL COMMENT 'RTSP SSL端口' AFTER `rtsp_port`;
ALTER TABLE `gv_media_servers` ADD COLUMN `rtmp_port` int(11) DEFAULT NULL COMMENT 'RTMP端口' AFTER `rtsp_ssl_port`;
ALTER TABLE `gv_media_servers` ADD COLUMN `rtmp_ssl_port` int(11) DEFAULT NULL COMMENT 'RTMP SSL端口' AFTER `rtmp_port`;
ALTER TABLE `gv_media_servers` ADD COLUMN `rtc_port` int(11) DEFAULT NULL COMMENT 'WebRTC端口' AFTER `rtmp_ssl_port`;
ALTER TABLE `gv_media_servers` ADD COLUMN `rtp_port_range` varchar(50) DEFAULT '30000-35000' COMMENT 'RTP端口范围' AFTER `rtc_port`;
ALTER TABLE `gv_media_servers` ADD COLUMN `default_snap` varchar(500) DEFAULT '' COMMENT '默认截图路径' AFTER `rtp_port_range`;
SQL);
    }
}
