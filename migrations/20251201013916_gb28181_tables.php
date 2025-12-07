<?php

use Phpmig\Migration\Migration;

class Gb28181Tables extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec(<<<SQL
-- GB28181 设备表
CREATE TABLE `gv_devices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `device_id` varchar(32) NOT NULL COMMENT '设备国标ID',
  `device_name` varchar(100) DEFAULT NULL COMMENT '设备名称',
  `device_type` varchar(100) DEFAULT NULL COMMENT '设备类型, 如IPC、DVR等',
  `status` enum('online','offline', 'expired', 'unregistered') NOT NULL DEFAULT 'offline' COMMENT '设备状态, online = 在线，offline = 离线，expired = 心跳过期，unregistered = 注销',
  `enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用',
  `sum_num` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '设备总数',
  `ip` varchar(45) DEFAULT NULL COMMENT '设备IP地址',
  `port` int(11) DEFAULT NULL COMMENT '设备端口',
  `from_uri` varchar(100) NOT NULL DEFAULT '' COMMENT 'SIP From 头',
  `contact` varchar(200) NOT NULL DEFAULT '' COMMENT 'SIP Contact 头',
  `user_agent` varchar(200) NOT NULL DEFAULT '' COMMENT '	\n设备固件或 SDK 标识',
  `expires` int(4) NOT NULL DEFAULT '0' COMMENT '注册有效期（秒）',
  `registered_at` datetime DEFAULT NULL COMMENT '注册时间',
  `last_heartbeat_at` datetime DEFAULT NULL COMMENT '最后心跳时间',
  `lat` varchar(40) NOT NULL DEFAULT '' COMMENT '设备同步的纬度',
  `lng` varchar(40) NOT NULL DEFAULT '' COMMENT '设备同步的经度',
  `custom_lat` varchar(40) NOT NULL DEFAULT '' COMMENT '自填纬度（标注或者手填）',
  `custom_lng` varchar(40) NOT NULL DEFAULT '' COMMENT '自填经度（标注或者手填）',
  `is_admin_area_pos` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否是行政区域经纬度信息',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_id` (`device_id`),
  KEY `idx_status` (`status`),
  KEY `idx_last_heartbeat` (`last_heartbeat_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GB28181设备表';

-- GB28181 设备通道表
CREATE TABLE IF NOT EXISTS `gv_device_channels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `device_id` varchar(32) NOT NULL COMMENT '设备国标ID',
  `channel_id` varchar(32) NOT NULL COMMENT '通道国标ID',
  `channel_type` varchar(32) DEFAULT NULL DEFAULT '' COMMENT '通道类型, video=视频,alarm=报警, audio=音频，other=其他',
  `channel_name` varchar(100) DEFAULT NULL DEFAULT '' COMMENT '通道名称',
  `manufacturer` varchar(100) DEFAULT NULL DEFAULT '' COMMENT '设备制造商',
  `owner` varchar(100) NOT NULL DEFAULT '' DEFAULT '' COMMENT '所属组织/用户',
  `model` varchar(100) NOT NULL DEFAULT '' DEFAULT '' COMMENT '设备型号',
  `parental` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否为父设备，0 = 叶子节点（视频通道），不可再挂子设备；1 = 可挂子设备（如 NVR、平台',
  `parent_id` varchar(32) DEFAULT NULL DEFAULT '' COMMENT '父级ID,所属上级设备（此处为一台 HCVR 或 NVR）',
  `civil_code` varchar(100) DEFAULT ''  COMMENT '行政区划代码,国标 6 位行政区划',
  `block` varchar(100) DEFAULT ''  COMMENT '所属区块或行业分类代码',
  `address` varchar(255) DEFAULT '' COMMENT '设备安装地址',
  `safety_way` tinyint(1) NOT NULL DEFAULT '0' COMMENT '安全方式,0 = 无加密（标准明文传输）',
  `register_way` tinyint(1) NOT NULL DEFAULT '1' COMMENT '注册方式,1 = 主动注册（设备向平台注册）；2 = 被动注册（平台主动发现）',
  `secrecy` tinyint(1) NOT NULL DEFAULT '1' COMMENT '保密等级,0 = 无保密要求；1~5 表示不同密级（公安场景使用）',
  `cert_num` varchar(100) NOT NULL DEFAULT '' COMMENT '扩展信息-证书数量',
  `certifiable` tinyint(1) NOT NULL DEFAULT '0' COMMENT '扩展信息-证书状态',
  `ip_address` varchar(32) NOT NULL DEFAULT '' COMMENT 'Ip 地址',
  `port` int(11) DEFAULT NULL COMMENT '设备端口',
  `err_code` varchar(32) NOT NULL DEFAULT '' COMMENT '设备注册或运行错误代码',
  `end_time` varchar(100) NOT NULL DEFAULT '' COMMENT '设备注册或权限的过期时间',
  `password` varchar(32) NOT NULL DEFAULT '' COMMENT '密码',
  `lat` varchar(40) NOT NULL DEFAULT '' COMMENT '设备同步的纬度',
  `lng` varchar(40) NOT NULL DEFAULT '' COMMENT '设备同步的经度',
  `custom_lat` varchar(40) NOT NULL DEFAULT '' COMMENT '自填纬度（标注或者手填）',
  `custom_lng` varchar(40) NOT NULL DEFAULT '' COMMENT '自填经度（标注或者手填）',
  `is_admin_area_pos` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否是行政区域经纬度信息',
  `main_id` varchar(32) NOT NULL COMMENT 'SSRC-CRC 32',
  `ssrc` varchar(10) NOT NULL COMMENT 'SSRC（10位数字）',
  `stream_id` varchar(64) NOT NULL COMMENT '流ID（{device_id}_{channel_id}）',
  `status` enum('online','offline', 'expired', 'unregistered','streaming') NOT NULL DEFAULT 'offline' COMMENT '通道状态',
  `enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用',
  `media_server_id` varchar(32) NOT NULL DEFAULT 'default' COMMENT '媒体服务器ID',
  `dept_id` varchar(24) NOT NULL DEFAULT '' COMMENT '部门代码（上报上级使用）',
  `dept_name` varchar(64) NOT NULL DEFAULT '' COMMENT '部门名称',
  `parent_dept_id` varchar(24) NOT NULL DEFAULT '' COMMENT '上级部门代码',
  `parent_dept_name` varchar(64) NOT NULL DEFAULT '' COMMENT '上级部门名称',
  `last_heartbeat_at` datetime DEFAULT NULL COMMENT '最后心跳时间',
  `close_live` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '是否关闭直播',
  `auto_live` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否为自动直播',
  `record_plan_id` int(10) NOT NULL DEFAULT '0' COMMENT '录像计划id',
  `record_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '录像状态（0=未录像，1=录像中）',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device_channel` (`device_id`,`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GB28181设备通道表';

-- GB28181 流会话表
CREATE TABLE IF NOT EXISTS `gv_stream_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `session_id` varchar(64) NOT NULL COMMENT '会话ID（自定义UUID）',
  `call_id` varchar(255) DEFAULT NULL COMMENT 'SIP Call-ID',
  `device_id` varchar(32) NOT NULL COMMENT '设备国标ID',
  `channel_id` varchar(32) NOT NULL COMMENT '通道国标ID',
  `ssrc` varchar(10) NOT NULL COMMENT 'SSRC',
  `stream_id` varchar(64) NOT NULL COMMENT '流ID',
  `type` enum('live','playback','download','talk') NOT NULL DEFAULT 'live' COMMENT '会话类型',
  `status` enum('inviting','active','stopped','error') NOT NULL DEFAULT 'inviting' COMMENT '会话状态',
  `zlm_port` int(11) DEFAULT NULL COMMENT 'ZLM RTP端口',
  `start_time` varchar(20) DEFAULT NULL COMMENT '回放开始时间',
  `end_time` varchar(20) DEFAULT NULL COMMENT '回放结束时间',
  `started_at` datetime NOT NULL COMMENT '开始时间',
  `stopped_at` datetime DEFAULT NULL COMMENT '结束时间',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_id` (`session_id`),
  KEY `idx_device_channel` (`device_id`,`channel_id`),
  KEY `idx_call_id` (`call_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GB28181流会话表';

-- 添加索引优化查询性能
ALTER TABLE `devices` ADD INDEX `idx_created_at` (`created_at`);
ALTER TABLE `device_channels` ADD INDEX `idx_device_id` (`device_id`);
ALTER TABLE `stream_sessions` ADD INDEX `idx_started_at` (`started_at`);

CREATE TABLE `gv_record_plan` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `partner_id` int(10) NOT NULL DEFAULT '0' COMMENT '所属合作方',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '录制计划名称',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用该录制计划(1=启用；0=禁用)',
  `remark` text COMMENT '录制计划的描述',
  `limit_space` bigint(20) NOT NULL DEFAULT '0' COMMENT '录制占用空间限制（Byte）,最大录制到某个值后做相应处理',
  `limit_days` int(6) NOT NULL DEFAULT '0' COMMENT '录制占用天数限制,最大录制到某个值后做相应处理',
  `over_step_plan` enum('stopDvr','delFile') NOT NULL DEFAULT 'delFile' COMMENT '超出天数限制后执行动作\n',
  `created_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updated_time` int(11) NOT NULL DEFAULT '0' COMMENT '修改时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='录制计划';
CREATE TABLE `gv_record_plan_range` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `record_plan_id` int(11) NOT NULL DEFAULT '0' COMMENT '计划任务表的主键',
  `week_day` varchar(16) NOT NULL DEFAULT '' COMMENT '星期n枚举',
  `start_time` varchar(32) NOT NULL COMMENT '录制开始时间(时分格式)',
  `end_time` varchar(32) NOT NULL DEFAULT '' COMMENT '录制结束时间',
  `created_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updated_time` int(11) NOT NULL COMMENT '修改时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='录制计划明细';
CREATE TABLE `gv_record_file` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键id',
  `main_id` varchar(64) NOT NULL DEFAULT '' COMMENT '设备唯一id',
  `media_server_id` varchar(64) NOT NULL COMMENT '流媒体服务器ID',
  `media_server_ip` varchar(64) NOT NULL COMMENT '流媒体服务器IP地址',
  `channel_id` varchar(64) NOT NULL COMMENT 'GB21818设备通道ID',
  `channel_name` varchar(255) NOT NULL COMMENT '通道名称',
  `device_id` varchar(64) NOT NULL COMMENT 'GB28181设备ID',
  `video_src_url` varchar(500) DEFAULT '' COMMENT '非gb28181设备的视频流源地址',
  `start_time` int(11) unsigned NOT NULL COMMENT '文件的开始时间',
  `end_time` int(11) NOT NULL COMMENT '文件的结束时间',
  `duration` int(10) DEFAULT NULL COMMENT '文件的时长(单位:s)',
  `video_path` varchar(255) DEFAULT NULL COMMENT '文件的所在位置',
  `file_size` bigint(20) DEFAULT '0' COMMENT '文件大小',
  `vhost` varchar(64) DEFAULT '' COMMENT 'vhost',
  `stream_id` varchar(64) DEFAULT '' COMMENT 'stream',
  `app` varchar(32) DEFAULT NULL COMMENT 'media server 流应用名',
  `download_url` varchar(255) DEFAULT '' COMMENT '文件下载与播放地址',
  `is_undo` tinyint(1) DEFAULT NULL COMMENT '是否可撤销删除操作(0:否，1：是）',
  `record_date` varchar(20) NOT NULL DEFAULT '' COMMENT '记录日期',
  `deleted_time` int(11) NOT NULL DEFAULT '0' COMMENT '是否删除',
  `created_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `updated_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  `plan_id` int(10) NOT NULL DEFAULT '0' COMMENT '录制计划id',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='录制文件实例';
SQL);
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
