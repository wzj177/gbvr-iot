<?php


namespace CoreW\Business\VIP\Token\Storage;


use CoreW\Business\VIP\Dao\TokenDao;
use CoreW\Context\BfwAware;

class DbTokenStorage implements TokenStorageInterface
{
    use BfwAware, \CoreW\Traits\Token\DbTokenStorage;

    /**
     * @return TokenDao
     */
    protected function getTokenDao()
    {
        return $this->bfw->dao('VIP:TokenDao');
    }
}