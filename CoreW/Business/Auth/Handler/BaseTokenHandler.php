<?php


namespace CoreW\Business\Auth\Handler;


use CoreW\Bfw;
use CoreW\Business\User\Service\TokenService;
use CoreW\Context\BfwAware;

class BaseTokenHandler
{
    use BfwAware;

    protected $config = [];

    private $tokenService;

    public function __construct(Bfw $biz, TokenService $tokenService)
    {
        $this->bfw = $biz;
        $this->tokenService = $tokenService;
    }


    /**
     * @param array $config
     */
    public function setConfig(array $config) : void
    {
        $this->config = $config;
    }

    /**
     * @param Bfw $bfw
     */
    public function setBfw(Bfw $bfw) : void
    {
        $this->bfw = $bfw;
    }

    /**
     * @return TokenService
     */
    public function getTokenService()
    {
        return $this->tokenService;
    }
}