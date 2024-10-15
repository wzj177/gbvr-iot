<?php


namespace CoreW\Business\User\Token\Storage;


use CoreW\Business\User\Dao\TokenDao;
use CoreW\Context\BfwAware;

class DbTokenStorage implements TokenStorageInterface
{
    use BfwAware, \CoreW\Traits\Token\DbTokenStorage;

    /**
     * @return TokenDao
     */
    protected function getTokenDao()
    {
        return $this->bfw->dao('User:TokenDao');
    }
}