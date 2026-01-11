<?php

use Phpmig\Migration\Migration;

/**
 * 设备配置扩展字段迁移
 * 
 * 添加 GB28181 设备配置相关字段：
 * - 信令/媒体服务器配置
 * - 流传输模式
 * - 订阅配置
 * - 字符集/码流设置
 * - 通道过滤
 */
class DeviceConfigExtend extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        
        // 1. 扩展 gv_devices 表 - 添加设备配置字段
        $container['db']->exec(<<<SQL
ALTER TABLE `gv_devices`
    -- 订阅配置
    ADD COLUMN `subscribe_catalog` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否订阅目录变更' AFTER `city_id`,
    ADD COLUMN `subscribe_alarm` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否订阅报警事件' AFTER `subscribe_catalog`,
    ADD COLUMN `subscribe_position` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否订阅位置上报' AFTER `subscribe_alarm`,
    ADD COLUMN `subscribe_ptz` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否订阅PTZ控制反馈(2022)' AFTER `subscribe_position`,
    ADD COLUMN `subscribe_expires` INT(11) NOT NULL DEFAULT 3600 COMMENT '订阅有效期（秒）' AFTER `subscribe_ptz`,
    ADD COLUMN `position_interval` INT(11) NOT NULL DEFAULT 60 COMMENT '位置上报间隔（秒）' AFTER `subscribe_expires`,
    
    -- 通道更新配置
    ADD COLUMN `catalog_interval` INT(11) NOT NULL DEFAULT 3600 COMMENT '通道目录更新周期（秒），0=禁用轮询' AFTER `position_interval`,
    ADD COLUMN `last_catalog_at` INT(11) NOT NULL DEFAULT 0 COMMENT '上次目录查询时间' AFTER `catalog_interval`,
    
    -- 字符集和码流
    ADD COLUMN `charset` ENUM('gb2312','utf8', 'auto') NOT NULL DEFAULT 'auto' COMMENT '设备XML字符集' AFTER `last_catalog_at`,
    ADD COLUMN `stream_index` VARCHAR(16) NOT NULL DEFAULT 'auto' COMMENT '码流索引: auto=自动, stream:0=stream:0 - 主码流,stream:1=stream:1 - 主码流,streamnumber:0=streamnumber:0 - 主码流(2022),streamnumber:1=streamnumber:1 - 子码流，streamprofile:0=streamprofile:0 - 主码流，streamprofile:1=streamprofile:1 - 子码流，streamMode:MAIN = streamMode:MAIN - 主码流，streamMode:SUB = streamMode:SUB - 子码流' AFTER `charset`,
    
    -- 通道过滤
    ADD COLUMN `filter_channel_types` JSON DEFAULT NULL COMMENT '过滤的通道类型列表，如[134,135]' AFTER `stream_index`,
        
    -- 订阅状态追踪
    ADD COLUMN `subscription_status` JSON DEFAULT NULL COMMENT '订阅状态信息，包含各类型订阅的状态和过期时间' AFTER `filter_channel_types`;
SQL
        );
        
        // 2. 添加索引优化查询
        $container['db']->exec(<<<SQL
ALTER TABLE `gv_devices`
    ADD INDEX `idx_subscribe_status` (`subscribe_catalog`, `subscribe_alarm`, `subscribe_position`);
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
ALTER TABLE `gv_devices`
    DROP INDEX `idx_subscribe_status`,
    DROP COLUMN `subscribe_catalog`,
    DROP COLUMN `subscribe_alarm`,
    DROP COLUMN `subscribe_position`,
    DROP COLUMN `subscribe_ptz`,
    DROP COLUMN `subscribe_expires`,
    DROP COLUMN `position_interval`,
    DROP COLUMN `catalog_interval`,
    DROP COLUMN `last_catalog_at`,
    DROP COLUMN `charset`,
    DROP COLUMN `stream_index`,
    DROP COLUMN `filter_channel_types`,
    DROP COLUMN `record_mode`,
    DROP COLUMN `catalog_structure`,
    DROP COLUMN `subscription_status`;
SQL
        );
    }
}
