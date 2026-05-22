<?php


namespace CoreW\RateLimiter\Limiters;


use CoreW\Bfw;
use CoreW\RateLimiter\RateLimiter;
use CoreW\RateLimiter\TimeMachine;

class LoginSendSmsRateLimiter extends BaseRateLimiter implements RateLimiterInterface
{

    const IP_MAX_ALLOW_ATTEMPT_ONE_HOUR = 2;

    //    const IP_MAX_ALLOW_ATTEMPT_ONE_MINUTE = 100;

    const SITE_MAX_ALLOW_ATTEMPT_ONE_DAY = 100;

    /**
     * @var RateLimiter
     */
    protected $ipHourRateLimiter;

    /**
     * @var RateLimiter
     */
    protected $siteDayRateLimiter;

    public function __construct(Bfw $bfw)
    {
        parent::__construct($bfw);
        $factory = $bfw['ratelimiter.factory'];
        $this->ipHourRateLimiter = $factory('login_sms_send.ip.one_hour', self::IP_MAX_ALLOW_ATTEMPT_ONE_HOUR, TimeMachine::ONE_HOUR);
        $this->siteDayRateLimiter = $factory('login_sms_send.site.one_day', self::SITE_MAX_ALLOW_ATTEMPT_ONE_DAY, TimeMachine::ONE_DAY);
    }

    public function handle($hourKey = null, $dayKey = null)
    {
        $ihr = $this->ipHourRateLimiter->check($hourKey);
        if ($ihr <= 0) {
            throw RateLimitException::FORBIDDEN_LOGIN_SMS_SEND_HOUR_MAX_REQUEST();
        }

        $sdr = $this->siteDayRateLimiter->check($dayKey);
        if ($sdr <= 0) {
            throw RateLimitException::FORBIDDEN_LOGIN_SMS_SEND_DAY_MAX_REQUEST();
        }
    }
}