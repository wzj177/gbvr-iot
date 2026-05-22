<?php

use Phpmig\Migration\Migration;

class CreateGbSessionViewersTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("
CREATE TABLE IF NOT EXISTS `gv_stream_session_viewers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '主键ID',
  `stream_id` varchar(64) NOT NULL COMMENT '流ID',
  `viewer_id` varchar(64) NOT NULL COMMENT '观众ID',
    `viewer_ip` varchar(64) NOT NULL COMMENT '观众IP',
  `created_at` datetime NOT NULL COMMENT '创建时间',
  `updated_at` datetime NOT NULL COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='GB28181流会话观众表';
        ");
    }

    /**
     * Undo the migration
     */
    public function down()
    {
        $container = $this->getContainer();
        $container['db']->exec("DROP TABLE IF EXISTS `gv_devgv_stream_session_viewersice_presets`;");
    }
}
