<?php

use Phpmig\Migration\Migration;

class CreateRecordTaskTable extends Migration
{
    /**
     * Do the migration
     */
    public function up()
    {
        $container = $this->getContainer();
        $container['db']->exec("CREATE TABLE gv_record_task (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  task_type ENUM('plan','alarm','playback_download') NOT NULL,
  device_id VARCHAR(64) NOT NULL,
  channel_id VARCHAR(64) NOT NULL,
  media_server_id VARCHAR(64) DEFAULT NULL,
  vhost VARCHAR(64) DEFAULT '__defaultVhost__',
  app VARCHAR(32) DEFAULT NULL,
  stream_id VARCHAR(64) DEFAULT NULL,
  dialog_id BIGINT DEFAULT NULL COMMENT '回放会话用',
  start_time DATETIME(3) NOT NULL,
  end_time DATETIME(3) NOT NULL,
  customized_path VARCHAR(255) DEFAULT NULL,
  status ENUM('pending','inviting','wait_stream','recording','finalizing','done','failed','cancelled') NOT NULL DEFAULT 'pending',
  fail_reason VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_task_type_time (task_type, start_time),
  INDEX idx_device_channel (device_id, channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='录像生成任务';
");
    }

    /**
     * Undo the migration
     */
    public function down()
    {

    }
}
