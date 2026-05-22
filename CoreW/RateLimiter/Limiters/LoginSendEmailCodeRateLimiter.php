<?php


namespace CoreW\RateLimiter\Limiters;


use CoreW\Bfw;
use CoreW\RateLimiter\RateLimiter;
use CoreW\RateLimiter\TimeMachine;

class LoginSendEmailCodeRateLimiter extends BaseRateLimiter implements RateLimiterInterface
{

    // 一个邮箱一小时允许发6次，每次有效期10分钟
    const EMAIL_MAX_ALLOW_ATTEMPT_ONE_HOUR = 60;


    /**
     * @var RateLimiter
     */
    protected $emailHourRateLimiter;


    public function __construct(Bfw $bfw)
    {
        parent::__construct($bfw);
        $factory = $bfw['ratelimiter.factory'];
        $this->emailHourRateLimiter = $factory('login_send_email_code.email.one_hour', self::EMAIL_MAX_ALLOW_ATTEMPT_ONE_HOUR, TimeMachine::ONE_HOUR);
    }

    public function handle($hourKey = null, $dayKey = null)
    {
        $ihr = $this->emailHourRateLimiter->check($hourKey);
        if ($ihr <= 0) {
            throw RateLimitException::FORBIDDEN_LOGIN_EMIAL_CODE_SEND_DAY_MAX_REQUEST();
        }
    }
}