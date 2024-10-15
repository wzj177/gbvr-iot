<?php

use Phpmig\Migration\Migration;

class AddProductScenePrFileId extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("ALTER TABLE `vr_product_scene` ADD COLUMN `prFileId` int(10) NOT NULL DEFAULT 0 COMMIT '全景图附件ID' AFTER `panoramaSmall`;");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
