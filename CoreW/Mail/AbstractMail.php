<?php


namespace CoreW\Mail;


use CoreW\Mail\Logger\JsonLogger;
use CoreW\Business\Setting\Service\SettingService;
use CoreW\Context\BfwAware;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

abstract class AbstractMail
{
    const MAX_LOG_SIZE = 52428800;

    use BfwAware;

    private $options;

    private $debug;

    /**
     * @var JsonLogger
     */
    protected $logger;

    public function __construct($options, $debug = false)
    {
        $this->options = $options;
        $this->debug = $debug;
        if ($debug) {
            $this->initLogger();
        }
    }

    public function __set($name, $value)
    {
        $this->options[$name] = $value;
    }

    public function __get($name)
    {
        if ('options' === $name) {
            return $this->options;
        }

        if (!array_key_exists($name, $this->options)) {
            return null;
        }

        return $this->options[$name];
    }

    public function __isset($name)
    {
        if ('options' === $name) {
            return $this->options !== null;
        }

        return isset($this->options[$name]);
    }

    public function __unset($name)
    {
        unset($this->options[$name]);

        return $this;
    }

    protected function parseTemplate($templateName)
    {
        return $this->bfw['email_template_parser']->parseTemplate($templateName, $this->options);
    }

    protected function initLogger()
    {
        $logFile = runtime_path() . '/logs/mail/' . date('Ym') . '/' . date('d') . '.log';
        if (is_file($logFile)) {
            $fileSize = filesize($logFile);
            clearstatcache(true, $logFile);
            $fileSize > self::MAX_LOG_SIZE && unlink($logFile);
        }

        $stream = new StreamHandler($logFile, Logger::DEBUG, true, 0777);
        $this->logger = new JsonLogger('mail', $stream);
    }

    public function send()
    {
        $this->mailCheckRateLimiter();

        return $this->doSend();
    }

    protected function getSettingConfig()
    {
        /** @var $settingService SettingService */
        $settingService = $this->bfw->service('Setting:SettingService');

        return $settingService->get('mail');
    }

    protected function mailCheckRateLimiter()
    {

    }

    abstract public function doSend();
}