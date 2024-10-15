<?php


namespace app\queue\redis\fast;

use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Core;
use Webman\RedisQueue\Consumer;

/**
 * 分片删除-清空分片碎片文件
 *
 * Class ClearUploadChunkFile
 * @package app\queue\redis\fast
 */
class ClearUploadChunkFile implements Consumer
{
    public $queue = 'clear-upload-chunk-file';
    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    public function consume($data)
    {
        if (!empty($data['chunkFiles'])) {
            foreach ($data['chunkFiles'] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }

        if (empty($data['chunkDir']) || !is_dir($data['chunkDir'])) {
            return;
        }

        $tmpFile = sprintf('%s/tmp', $data['chunkDir']);
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }

        $tmpIndexFile = sprintf('%s/index_tmp', $data['chunkDir']);
        if (is_file($tmpIndexFile)) {
            @unlink($tmpIndexFile);
        }

        @rmdir($data['chunkDir']);
    }

    /**
     * @return AttachmentService
     */
    protected function getAttachmentService()
    {
        return $this->getBiz()->service('Attachment:AttachmentService');
    }

    protected function getBiz()
    {
        return Core::instance();
    }
}