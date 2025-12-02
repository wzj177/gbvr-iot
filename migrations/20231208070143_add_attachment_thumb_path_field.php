<?php

use Phpmig\Migration\Migration;

class AddAttachmentThumbPathField extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `gv_attachment` 
ADD COLUMN `thumbPath` varchar(255) NOT NULL COMMENT '图片缩略图路径' AFTER `updatedTime`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
