<?php


namespace CoreW\Business\User;


use Symfony\Component\Security\Core\User\UserInterface;

interface CurrentUserInterface extends UserInterface
{
    public function getId();

    public function isLogin();
}