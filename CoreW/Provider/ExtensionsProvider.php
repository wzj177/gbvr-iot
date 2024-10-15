<?php


namespace CoreW\Provider;


use CoreW\Bfw;
use CoreW\Business\Ip2Region\Ip2Region;
use CoreW\Business\Auth\AuthFactory;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Pimple\Container;
use Pimple\ServiceProviderInterface;
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
    }
}