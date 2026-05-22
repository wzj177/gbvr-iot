<?php

use Phpmig\Migration\Migration;

class CreateSipGatewaysTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "CREATE TABLE IF NOT EXISTS `gv_sip_gateways` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `gateway_id` varchar(64) NOT NULL COMMENT '网关唯一标识',
            `gateway_name` varchar(100) NOT NULL COMMENT '网关名称',
            `server_id` varchar(32) NOT NULL COMMENT '20位国标编码',
            `server_domain` varchar(16) NOT NULL COMMENT 'SIP域',
            `sip_host` varchar(45) NOT NULL DEFAULT '0.0.0.0' COMMENT 'SIP监听地址',
            `sip_port` int(11) NOT NULL DEFAULT 5060 COMMENT 'SIP监听端口',
            `transport` varchar(10) NOT NULL DEFAULT 'UDP' COMMENT 'SIP传输协议',
            `public_ip` varchar(45) DEFAULT '' COMMENT '公网IP',
            `device_password` varchar(100) DEFAULT '' COMMENT '设备接入密码',
            `authentication` tinyint(1) DEFAULT 1 COMMENT '是否启用认证',
            `sip_username` varchar(50) DEFAULT '' COMMENT 'SIP用户名',
            `register_expires` int(11) DEFAULT 3600 COMMENT '注册有效期(秒)',
            `keepalive_interval` int(11) DEFAULT 60 COMMENT '心跳间隔(秒)',
            `heartbeat_timeout` int(11) DEFAULT 180 COMMENT '心跳超时(秒)',
            `keepalive_lost_number` int(11) DEFAULT 3 COMMENT '心跳丢失次数阈值',
            `catalog_auto_query` tinyint(1) DEFAULT 1 COMMENT '注册后自动查询目录',
            `encoding_type` varchar(10) DEFAULT 'GB2312' COMMENT '字符编码',
            `task_worker_num` int(11) DEFAULT 4 COMMENT 'Task进程数',
            `timer_interval` int(11) DEFAULT 60 COMMENT '定时器间隔(秒)',
            `max_devices` int(11) DEFAULT 10000 COMMENT '最大设备数',
            `broadcast_push_after_ack` tinyint(1) DEFAULT 1 COMMENT '广播是否等ACK后推流',
            `mq_type` varchar(20) NOT NULL DEFAULT 'redis' COMMENT '消息队列类型: redis/rabbitmq',
            `mq_config` text COMMENT 'MQ连接配置JSON',
            `redis_config` text COMMENT 'Redis连接配置JSON',
            `api_config` text COMMENT 'API回调配置JSON',
            `log_level` varchar(10) DEFAULT 'INFO' COMMENT '日志级别',
            `debug` tinyint(1) DEFAULT 0 COMMENT '调试模式',
            `status` varchar(20) NOT NULL DEFAULT 'active' COMMENT '状态: active/inactive/disabled',
            `last_seen_at` datetime DEFAULT NULL COMMENT '最后心跳时间',
            `pid` int(11) DEFAULT NULL COMMENT '进程PID',
            `ip` varchar(45) DEFAULT NULL COMMENT '运行IP',
            `device_count` int(11) DEFAULT 0 COMMENT '在线设备数',
            `created_at` datetime NOT NULL,
            `updated_at` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_gateway_id` (`gateway_id`),
            UNIQUE KEY `uk_sip_host_port` (`sip_host`, `sip_port`),
            KEY `idx_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='SIP网关实例表';";

        $db->exec($sql);
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        $sql = "DROP TABLE IF EXISTS `gv_sip_gateways`;";
        $db->exec($sql);
    }
}
