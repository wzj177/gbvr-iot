<?php


namespace app\queue\redis\fast;


use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Core;
use support\utils\AssetHelper;
use Webman\RedisQueue\Consumer;

class RemoveAttachmentFiles implements Consumer
{
    public $queue = 'remove-attachment-file';
    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    public function consume($data)
    {
        if (empty($data['paths'])) {
            return false;
        }

        $uploadPath = uploads_path();
        foreach ($data['paths'] as $path) {
            $this->getAttachmentService()->unlinkFile('local', $path, $uploadPath);
        }

        return true;
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