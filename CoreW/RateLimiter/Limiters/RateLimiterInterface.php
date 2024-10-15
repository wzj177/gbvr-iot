<?php


namespace CoreW\RateLimiter\Limiters;


interface RateLimiterInterface
{
    const PASS = 30000;

    const CAPTCHA_OCCUR = 30001;

    const MAX_REQUEST_OCCUR = 30002;

    const MAX_REQUEST_MSG_KEY = 'request.max_attempt_reach';

    public function handle($hourKey = null, $dayKey = null);
}