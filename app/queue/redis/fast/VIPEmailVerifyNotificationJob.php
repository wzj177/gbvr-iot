<?php


namespace app\queue\redis\fast;


use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Business\VIP\Service\VIPService;
use CoreW\Core;
use CoreW\Mail\AbstractMail;
use support\utils\AssetHelper;
use Webman\RedisQueue\Consumer;

/**
 * 推送邮箱验证
 *
 * Class VIPEmailVerifyJob
 * @package app\queue\redis\fast
 */
class VIPEmailVerifyNotificationJob implements Consumer
{
    public $queue = 'vip-email-verify-notification';

    public $connection = 'default';

    public function consume($vip)
    {
        $this->getVIPService()->sendEmailVerifyNotification($vip);

        return true;
    }

    protected function getBiz()
    {
        return Core::instance();
    }

    /**
     * @return VIPService
     */
    protected function getVIPService()
    {
        return $this->getBiz()->service('VIP:VIPService');
    }

    /**
     * @return SystemLogService
     */
    protected function getSystemLogService()
    {
        return $this->getBiz()->service('SystemLog:SystemLogService');
    }
}