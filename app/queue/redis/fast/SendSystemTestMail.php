<?php


namespace app\queue\redis\fast;


use CoreW\Business\Attachment\Service\AttachmentService;
use CoreW\Core;
use CoreW\Mail\AbstractMail;
use Webman\RedisQueue\Consumer;

class SendSystemTestMail implements Consumer
{
    public $queue = 'send-system-test-mail';

    // 连接名，对应 plugin/webman/redis-queue/redis.php 里的连接`
    public $connection = 'default';

    // 消费
    public function consume($data)
    {
        $mailFactory = $this->getBiz()->offsetGet('mail_factory');
        /** @var $mail AbstractMail */
        $mail = $mailFactory($data);
        try {
            $mail->send();
            return 1;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    protected function getBiz()
    {
        return Core::instance();
    }

    /**
     * @return AttachmentService
     */
    protected function getAttachmentService()
    {
        return $this->getBiz()->service('Attachment:AttachmentService');
    }
}