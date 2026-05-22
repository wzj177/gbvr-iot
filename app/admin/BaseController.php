<?php

namespace app\admin;

use app\AbstractController;
use CoreW\Business\SystemLog\Service\SystemLogService;
use CoreW\Business\User\CurrentUserInterface;
use CoreW\Business\User\Service\UserService;
use CoreW\Exception\BadRequestHttpException;
use Webman\App;

class BaseController extends AbstractController
{

    /**
     * @return CurrentUserInterface
     */
    protected function getCurrentUser() : CurrentUserInterface
    {
        $biz = $this->getBiz();
        $biz['user']['currentIp'] = request()->getRemoteIp();
        return $biz['user'];
    }

    /**
     * @return UserService
     */
    protected function getUserService() : UserService
    {
        return $this->createService('User:UserService');
    }
}