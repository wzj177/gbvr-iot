<?php

use Phpmig\Migration\Migration;

class RenameRecordTaskToLocalRecordTask extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();

        // 重命名表
        $container['db']->exec("RENAME TABLE `gv_record_task` TO `gv_local_record_task`");

        // 去掉 task_type 中不再使用的 'plan' 枚举值
        $container['db']->exec("
            ALTER TABLE `gv_local_record_task`
            MODIFY COLUMN `task_type` enum('alarm','playback_download') NOT NULL
        ");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();

        $container['db']->exec("
            ALTER TABLE `gv_local_record_task`
            MODIFY COLUMN `task_type` enum('plan','alarm','playback_download') NOT NULL
        ");

        $container['db']->exec("RENAME TABLE `gv_local_record_task` TO `gv_record_task`");
    }
}
