<?php

use Phpmig\Migration\Migration;

class Gb28181VoiceSessions extends Migration
{
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("
            CREATE TABLE `gv_voice_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `session_id` varchar(64) NOT NULL COMMENT '会话ID（自定义UUID）',
  `device_id` varchar(32) NOT NULL COMMENT '设备国标ID',
  `channel_id` varchar(32) NOT NULL COMMENT '通道国标ID',
  `stream` varchar(100) DEFAULT NULL COMMENT 'ZLM流ID (app/stream)',
  `ssrc` varchar(20) DEFAULT NULL COMMENT 'SSRC',
  `media_server_id` varchar(64) DEFAULT NULL,
  `rtp_port` int(11) NOT NULL DEFAULT '0' COMMENT '前端推流到 ZLM 的 RTP 收流端口',
  `rtp_local_port` int(11) NOT NULL DEFAULT '0' COMMENT ' ZLM 向设备转发 RTP 的发流端口',
  `media_server_ip` varchar(50) DEFAULT NULL COMMENT '流媒体ip',
  `device_ip` varchar(50) DEFAULT NULL COMMENT '设备音频接收IP',
  `device_port` int(11) DEFAULT NULL COMMENT '设备音频接收端口',
  `dialog_id` varchar(32) DEFAULT NULL COMMENT 'SIP Dialog-ID',
  `call_id` varchar(32) DEFAULT NULL COMMENT 'SIP Call-ID',
  `receive_stream` varchar(100) DEFAULT NULL COMMENT '接收的流',
  `mode` enum('talk','broadcast') DEFAULT 'talk' COMMENT '对讲/广播',
  `rtp_tcp` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '传输模式: 0=UDP, 1=TCP被动, 2=TCP主动',
  `status` enum('waiting_stream','stream_arrived','inviting','connected','ended','failed') DEFAULT 'waiting_stream' COMMENT '会话状态: waiting_stream-等待推流, stream_arrived-流已到达, inviting-信令已发送, connected-已连接, ended-已结束, failed-失败',
  `sdp` text COMMENT 'SDP信息',
  `started_at` datetime DEFAULT NULL COMMENT '开始时间',
  `ended_at` datetime DEFAULT NULL COMMENT '结束时间',
  `expires_at` datetime DEFAULT NULL COMMENT '会话超时时刻',
  `version` int(11) NOT NULL DEFAULT '0' COMMENT '乐观锁版本',
  `ended_reason` varchar(32) DEFAULT NULL COMMENT '结束原因: manual/timeout/stream_departure/rtp_error/sip_error',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_id` (`session_id`),
  KEY `idx_device_channel` (`device_id`,`channel_id`),
  KEY `idx_stream` (`stream`),
  KEY `idx_status` (`status`),
  KEY `idx_dialog_id` (`dialog_id`),
  KEY `idx_expires_at` (`expires_at`,`status`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8mb4 COMMENT='GB28181语音对讲会话表';
        ");
    }

    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_gb28181_voice_sessions`");
    }
}
