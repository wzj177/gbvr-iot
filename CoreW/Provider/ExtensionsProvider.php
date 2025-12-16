<?php


namespace CoreW\Provider;


use CoreW\Bfw;
use CoreW\Business\Ip2Region\Ip2Region;
use CoreW\Business\Auth\AuthFactory;
use CoreW\Sdk\AMapSdk\AMapClient;
use CoreW\Sdk\PSipGateway\Gb28181Client;
use CoreW\Sdk\LeChangeSdk\Controller as LeChangeSdk;
use CoreW\Sdk\Ys7Sdk\OpenYs7;
use CoreW\Sdk\ZLMediaKit\ZLMClient;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
use support\Redis;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

class ExtensionsProvider implements ServiceProviderInterface
{
    public function register(Container $biz)
    {
        $biz['ip2region'] = function () {
            return new Ip2Region();
        };

        $biz['api.security.token_storage'] = function ($biz) {
            return new TokenStorage();
        };

        $biz['admin_auth'] = function ($app) {
            return function () use ($app) {
                $handler = config('auth.admin_token_handler');
                /** @var $app Bfw */
                return AuthFactory::auth($handler, $app, $app->service('User:TokenService'));
            };
        };

        $biz['api_auth'] = function ($app) {
            return function () use ($app) {
                $handler = config('auth.api_token_handler');
                /** @var $app Bfw */
                return AuthFactory::auth($handler, $app, $app->service('VIP:TokenService'));
            };
        };

        $biz['ffmpeg'] = function ($app) {
            $config = config('ffmpeg');
            if (empty($config['ffmpeg_bin'])) {
                return null;
            }
            $handler = new RotatingFileHandler($config['log_file']);
            $handler->setFormatter(new LineFormatter(null, 'Y-m-d H:i:s', true));
            $ffmpeg = \FFMpeg\FFMpeg::create([
                'ffmpeg.binaries' => $config['ffmpeg_bin'],
                'ffprobe.binaries' => $config['ffprobe_bin'],
                'timeout' => $config['timeout'], // The timeout for the underlying process
                'ffmpeg.threads' => $config['threads'],   // The number of threads that FFMpeg should use
            ], new Logger('ffmpeg', [$handler]));

            return $ffmpeg;
        };

        $biz['gb28181_gateway_sdk'] = function ($app) {
            return new Gb28181Client(Redis::connection('gb_gateway'));
        };

        $biz['redis.api.cache'] = function ($biz) {
            return Redis::connection('api_cache');
        };


        $biz['ip2region'] = function () {
            return new Ip2Region();
        };

        $biz['amap_sdk'] = function () {
            $config = \config('gis.gaode', []);
            return new AMapClient($config);
        };

        $biz['sip.ys7_sdk'] = function () {
            return function ($params, $debug) {
                return new OpenYs7($params, $debug);
            };
        };

        $biz['sip.le_change_sdk'] = function () {
            return function ($params, $debug) {
                return new LeChangeSdk($params['appKey'], $params['appSecret'], $debug, $params['apiUrl'] ??  null);
            };
        };

        $biz['zlm_sdk'] = function () {
            return new ZLMClient([
                'host' => config('zlm.host', '127.0.0.1'),
                'port' => config('zlm.port', 80),
                'secret' => config('zlm.secret', ''),
                'debug' => config('zlm.debug', false),
            ]);
        };
    }
}