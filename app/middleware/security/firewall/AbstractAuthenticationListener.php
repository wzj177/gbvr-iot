<?php

namespace app\middleware\security\firewall;


use CoreW\Bfw as Biz;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Support\Request;

abstract class AbstractAuthenticationListener implements ListenerInterface
{
    /**
     * @var Biz $biz;
     */
    protected $biz;

    public function __construct(Biz $biz)
    {
        $this->biz = $biz;
    }


    /**
     * @param Request $request
     * @param int|array $user
     * @param string $loginToken
     * @return mixed
     */
    abstract protected function createApiTokenFromRequest(Request $request, $user, $loginToken = '');

    /**
     * @return TokenStorageInterface
     */
    protected function getApiTokenStorage()
    {
        return $this->biz['api.security.token_storage'];
    }
}