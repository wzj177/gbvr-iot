<?php


namespace CoreW\Traits;


trait UserSessionTrait
{
    protected function setCurrentUser($currentUser)
    {
        $biz = $this->getBiz();
        $biz['user'] = $currentUser;

        return $this;
    }

}