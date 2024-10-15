<?php


namespace app\api;


use app\AbstractController;
use CoreW\Business\VIP\CurrentUser;
use CoreW\Business\VIP\Exception\VIPException;
use CoreW\Business\VIP\Service\VIPService;

class BaseController extends AbstractController
{
    /**
     * 是否是游客访问
     * @return bool
     */
    protected function isGuestVIPUser(): bool
    {
        return !$this->getBiz()->offsetExists('vip');
    }

    /**
     * @return CurrentUser
     */
    protected function getVIPInfo(): CurrentUser
    {
        $biz = $this->getBiz();
        if (!$biz->offsetExists('vip')) {
            throw VIPException::EXPIRED_OR_NOTFOUND_TOKEN();
        }

        $biz['vip']['currentIp'] = request()->getRemoteIp();

        return $biz['vip'];
    }

    protected function getUserId(): int
    {
        return (int)$this->getVIPInfo()->getId();
    }

    /**
     * @return VIPService
     */
    protected function getVIPService()
    {
        return $this->createService('VIP:VIPService');
    }
}