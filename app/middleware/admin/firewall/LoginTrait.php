<?php


namespace app\middleware\admin\firewall;


use app\middleware\security\authentication\token\ApiToken;
use CoreW\Business\User\CurrentUser;
use CoreW\Business\User\Exception\UserException;
use CoreW\Business\User\Service\Impl\Handler\TokenHandlerInterface;
use CoreW\Business\User\Service\TokenService;
use CoreW\Business\User\Service\UserService;
use support\Redis;
use support\Request;

/**
 * Trait LoginTrait
 * @package app\middleware\admin\firewall
 */
trait LoginTrait
{
    /**
     * 校验用户
     *
     * @param Request $request
     * @param $admin
     * @param $password
     * @return mixed
     */
    protected function validateUser(Request $request, $admin, $password)
    {
        if (empty($admin) || empty($password)) {
            throw UserException::USERNAME_PASSWORD_ERROR();
        }

        $checkCaptcha = $request->post('checkCaptcha', false);

        if ($checkCaptcha) {
            $captcha = $request->post('captcha');
            if (empty($captcha)) {
                throw UserException::CAPTCHA_EMPTY();
            }

            if (strtolower($captcha) !== Redis::get('admin:captcha')) {
                throw UserException::CAPTCHA_ERROR();
            }
        }

        $user = $this->getUserService()->getUserByLoginField($admin);
        if (empty($user)) {
            throw UserException::NOTFOUND_USER();
        }

        if (!$this->getUserService()->verifyPasswordByUser($user, $password)) {
            // TODO: 登录保护：输错5次锁定用户
            throw UserException::PASSWORD_ERROR();
        }


        if ($user['locked']) {
            throw UserException::LOCKED_USER();
        }

        return $user;
    }
}