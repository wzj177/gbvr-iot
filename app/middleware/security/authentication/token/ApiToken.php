<?php

namespace app\middleware\security\authentication\token;

use CoreW\Business\User\CurrentUserInterface;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;

class ApiToken extends AbstractToken
{
    public function __construct(CurrentUserInterface $user, array $roles = [])
    {
        parent::__construct($roles);
        $this->setUser($user);
        parent::setAuthenticated($user->isLogin());
    }

    /**
     * {@inheritdoc}
     */
    public function setAuthenticated($isAuthenticated)
    {
        if ($isAuthenticated) {
            throw new \LogicException('Cannot set this token to trusted after instantiation.');
        }

        parent::setAuthenticated(false);
    }

    /**
     * {@inheritdoc}
     */
    public function getCredentials()
    {
        return null; // 凭证信息不在此存储
    }

    /**
     * {@inheritdoc}
     */
    public function eraseCredentials()
    {
        parent::eraseCredentials();
    }
}