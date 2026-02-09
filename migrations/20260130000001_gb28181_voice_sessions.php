<?php

use Phpmig\Migration\Migration;

class Gb28181VoiceSessions extends Migration
{
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("
            CREATE TABLE IF NOT EXISTS `gv_voice_sessions` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
                `session_id` varchar(64) NOT NULL COMMENT '会话ID（自定义UUID）',
                `device_id` varchar(32) NOT NULL COMMENT '设备国标ID',
                `channel_id` varchar(32) NOT NULL COMMENT '通道国标ID',
                `stream` varchar(100) DEFAULT NULL COMMENT 'ZLM流ID (app/stream)',
                `receive_stream` varchar(100) DEFAULT NULL COMMENT '接收ZLM流ID (app/stream)',
                `ssrc` varchar(20) DEFAULT NULL COMMENT 'SSRC',
                `rtp_port` int(11) NOT NULL DEFAULT '0' COMMENT '流媒体 RTP端口',
                `rtp_local_port` int(11) NOT NULL DEFAULT '0' COMMENT 'rtp被动推流端口号'
                `media_server_ip` varchar(50) DEFAULT NULL COMMENT '流媒体 IP',
                `media_server_id` varchar(50) DEFAULT NULL COMMENT '流媒体 ID',
                `device_ip` varchar(50) DEFAULT NULL COMMENT '设备音频接收IP',
                `device_port` int(11) DEFAULT NULL COMMENT '设备音频接收端口',
                `dialog_id` varchar(32) DEFAULT NULL COMMENT 'SIP Dialog-ID',
                `call_id` varchar(32) DEFAULT NULL COMMENT 'SIP Call-ID',
                `mode` enum('talk','broadcast') DEFAULT 'talk' COMMENT '对讲/广播',
                `status` enum('waiting_stream','stream_arrived','inviting','established','ended','failed') DEFAULT 'waiting_stream' COMMENT '会话状态',
                `sdp` text DEFAULT NULL COMMENT 'SDP信息',
                `started_at` datetime DEFAULT NULL COMMENT '开始时间',
                `ended_at` datetime DEFAULT NULL COMMENT '结束时间',
                `created_at` datetime NOT NULL COMMENT '创建时间',
                `updated_at` datetime NOT NULL COMMENT '更新时间',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_session_id` (`session_id`),
                KEY `idx_device_channel` (`device_id`,`channel_id`),
                KEY `idx_stream` (`stream`),
                KEY `idx_status` (`status`),
                KEY `idx_dialog_id` (`dialog_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GB28181语音对讲会话表';
        ");
    }

    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_gb28181_voice_sessions`");
    }
}
