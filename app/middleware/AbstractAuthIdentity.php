<?php


namespace app\middleware;


use app\middleware\security\firewall\ListenerInterface;
use CoreW\Bfw;
use CoreW\Core;
use CoreW\Exception\AbstractBizException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

abstract class AbstractAuthIdentity
{
    /**
     * @var string 登录用户全局对象标识：user=管理端；vip=会员端
     */
    protected $currentUserIndex = 'user';

    /**
     * @var ListenerInterface
     */
    protected $authenticationListener;

    protected $noRequiredAuthRoutes = [];

    /**
     * @param $route
     * @return bool
     * @deprecated
     */
    protected function routeIsRequiredAuth($route)
    {
        $route = ltrim($route, '/');
        $flag = true;
        foreach ($this->noRequiredAuthRoutes as $noRequiredAuthRoute) {
            $parseRoute = explode('/', $noRequiredAuthRoute);
            $level = count($parseRoute);
            if ($level == 1 && current($parseRoute) === '*') {
                $flag = false;
                break;
            }

            if ($level == 2 && false !== strpos($route, $parseRoute[0]) && $parseRoute[1] === '*') {
                $flag = false;
                break;
            }

            if ($level == 3 && false !== strpos($route, $parseRoute[0] . '/' . $parseRoute[1]) && $parseRoute[2] === '*') {
                $flag = false;
                break;
            }

            if ($noRequiredAuthRoute === $route) {
                $flag = false;
                break;
            }
        }

        return $flag;
    }

    /**
     * TODO: 应该传递TokenUser
     */
    protected function identity()
    {
        $this->getBiz()->offsetSet($this->currentUserIndex, $this->getApiSecurityTokenStorage()->getToken()->getUser());

        // token单例保存了用户的登录信息，但是我们将信息转存到biz ，则清空
        $this->getApiSecurityTokenStorage()->setToken(null);
    }

    /**
     * @return TokenStorageInterface
     */
    protected function getApiSecurityTokenStorage()
    {
        return $this->getBiz()->offsetGet('api.security.token_storage');
    }

    /**
     * @return Bfw
     */
    protected function getBiz()
    {
        return Core::instance();
    }

    /**
     * @param $e
     * @throws \Exception
     */
    protected function createNewException($e)
    {
        if ($e instanceof AbstractBizException) {
            throw $e;
        }

        throw new \Exception($e);
    }
}