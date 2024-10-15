<?php


namespace CoreW\RateLimiter\Limiters;


use CoreW\Bfw;

class BaseRateLimiter
{
    protected $bfw;

    public function __construct(Bfw $bfw)
    {
        $this->bfw = $bfw;
    }

    protected function createMaxRequestOccurException()
    {
        return RateLimitException::FORBIDDEN_MAX_REQUEST();
    }

}