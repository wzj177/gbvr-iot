<?php

use Phpmig\Migration\Migration;

class AlterRecordFileAddAiFields extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE gv_record_file
ADD COLUMN asset_id CHAR(36) DEFAULT NULL COMMENT '全局资产ID(UUID)',
ADD COLUMN index_status ENUM('NONE','INDEXING','INDEXED','FAILED') DEFAULT 'NONE',
ADD COLUMN embedding_version VARCHAR(32) DEFAULT NULL,
ADD COLUMN ai_meta JSON DEFAULT NULL COMMENT 'AI元数据（标签/摘要/向量ID等）';
");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
