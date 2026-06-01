<?php

use Phpmig\Migration\Migration;

class VoiceSessionOptimization extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        // 添加新字段到 gv_gb28181_voice_sessions 表
        $db->exec("
            ALTER TABLE `gv_voice_sessions`
            ADD COLUMN `expires_at` DATETIME NULL COMMENT '会话超时时刻' AFTER `updated_at`,
            ADD COLUMN `version` INT NOT NULL DEFAULT 0 COMMENT '乐观锁版本' AFTER `expires_at`,
            ADD COLUMN `ended_reason` VARCHAR(32) NULL COMMENT '结束原因(超时/手动/错误)' AFTER `version`
        ");

        // 添加索引优化超时查询
        $db->exec("
            ALTER TABLE `gv_voice_sessions`
            ADD INDEX `idx_status_expires` (`status`, `expires_at`)
        ");

        // 更新状态枚举值
        $db->exec("
            ALTER TABLE `gv_voice_sessions`
            MODIFY COLUMN `status` ENUM('waiting_stream','stream_arrived','inviting','connected','failed','ended')
            NOT NULL DEFAULT 'waiting_stream' COMMENT '会话状态'
        ");

        // 为现有数据设置默认值
        $db->exec("
            UPDATE `gv_voice_sessions`
            SET `version` = 0
            WHERE `version` IS NULL OR `version` = 0
        ");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $db = $container['db'];

        // 删除索引
        $db->exec("
            ALTER TABLE `gv_gb28181_voice_sessions`
            DROP INDEX `idx_status_expires`
        ");

        // 删除新字段
        $db->exec("
            ALTER TABLE `gv_gb28181_voice_sessions`
            DROP COLUMN `expires_at`,
            DROP COLUMN `version`,
            DROP COLUMN `ended_reason`
        ");

        // 恢复原来的状态枚举
        $db->exec("
            ALTER TABLE `gv_gb28181_voice_sessions`
            MODIFY COLUMN `status` ENUM('waiting','inviting','established','ended','error')
            NOT NULL DEFAULT 'waiting' COMMENT '会话状态'
        ");
    }
}
