<?php

use Phpmig\Migration\Migration;

class FixRecordPlanAddIdColumn extends Migration
{
    public function up()
    {
        $container = $this->getContainer();


        // 给 gv_record_plan_range 加索引方便查询
        $container['db']->exec("ALTER TABLE `gv_record_plan_range` ADD INDEX `idx_record_plan_id` (`record_plan_id`)");

        // 给 gv_record_file 加计划相关索引
        $container['db']->exec("ALTER TABLE `gv_record_file` ADD INDEX `idx_plan_id` (`plan_id`)");
        $container['db']->exec("ALTER TABLE `gv_record_file` ADD INDEX `idx_source_type_source_id` (`source_type`, `source_id`)");
    }

    public function down()
    {
    }
}
