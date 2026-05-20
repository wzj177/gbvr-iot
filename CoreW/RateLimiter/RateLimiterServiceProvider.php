<?php


namespace CoreW\RateLimiter;


use CoreW\RateLimiter\Limiters\LoginSendEmailCodeRateLimiter;
use CoreW\RateLimiter\Limiters\LoginSendSmsRateLimiter;
use CoreW\RateLimiter\Storage\MySQLPDOStorage;
use CoreW\RateLimiter\Storage\RedisStorage;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use CoreW\RateLimiter\RateLimiter;

class RateLimiterServiceProvider implements ServiceProviderInterface
{
    public function register(Container $container)
    {
        $container['ratelimiter.factory'] = function ($container) {
            return function ($name, $maxAllowance, $period) use ($container) {
                return new RateLimiter($name, $maxAllowance, $period, $container['ratelimiter.storage']);
            };
        };

        $container['ratelimiter.storage'] = function ($container) {
            return $container['ratelimiter.storage.' . $container['ratelimiter.storage_name']];
        };

        $container['ratelimiter.storage.mysql'] = function ($container) {
            /** @var $db \CoreW\Dao\Connection */
            $db = $container['db'];
            $pdo = $db->getNativeConnection();

            return new MySQLPDOStorage($pdo);
        };

        $container['ratelimiter.storage.redis'] = function ($container) {
            return new RedisStorage();
        };

        $container['ratelimiter.storage_name'] = 'mysql';

        $container['login_send_sms_rate_limiter'] = function ($bfw) {
            return new LoginSendSmsRateLimiter($bfw);
        };

        $container['login_send_email_code_rate_limiter'] = function ($bfw) {
            return new LoginSendEmailCodeRateLimiter($bfw);
        };
    }
}