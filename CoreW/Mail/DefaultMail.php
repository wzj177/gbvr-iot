<?php


namespace CoreW\Mail;


use CoreW\Exception\InvalidParamException;
use CoreW\RateLimiter\Limiters\RateLimiterInterface;

class DefaultMail extends AbstractMail
{
    /**
     * @return bool
     */
    public function doSend()
    {
        $format = isset($this->format) && 'html' == $this->format ? 'text/html' : 'text/plain';
        $config = $this->getSettingConfig();
        if (empty($config)) {
            throw new InvalidParamException("Not Found Default Mail Server Config");
        }

        if (isset($config['enabled']) && 1 == $config['enabled']) {
            $security = null;
            if (465 == $config['port'] || 587 == $config['port']) {
                $security = 'ssl';
            }

            $transport = new \Swift_SmtpTransport($config['host'], $config['port'], $security);
            $transport->setUsername($config['username'])->setPassword($config['password']);

            $mailer = new \Swift_Mailer($transport);

            $email = new \Swift_Message();

            $template = $this->parseTemplate($this->options['template']);
            if (isset($template['format'])) {
                $format = $template['format'];
            }

            $email->setSubject($template['title']);

            $email->setFrom([$config['from'] => $config['name'] ?? 'EasyVR']);


            if (is_array($this->to)) {
                foreach ($this->to as $key => $to) {
                    if (!isset($this->toName[$key])) {
                        continue;
                    }
                    $email->addBcc($to, $this->toName[$key] ?? null);
                }
            } else {
                $email->setBcc($this->to, $this->toName ?? null);
            }

            if ('text/html' == $format) {
                $email->setBody($template['body'], 'text/html');
            } else {
                $email->setBody($template['body']);
            }

            try {
                $mailer->send($email);
                $this->log("send  email succeeded", $template);

                return true;

            } catch (\Exception $e) {
                $this->log("send  email failed:{$e->getMessage()}", $template, 'error');
                throw $e;
            }
        }

    }

    protected function log($msg, $content = [], $type = 'info')
    {
        if ($this->logger) {
            if (method_exists($this->logger, $type)) {
                $this->logger->{$type}($msg, $content);
            } else {
                $this->logger->info($msg, $content);
            }
        }
    }

    protected function mailCheckRateLimiter()
    {
        $template = $this->options['template'];
        $key = $template . '_rate_limiter';
        if (!$this->bfw->offsetExists($key)) {
            return;
        }

        /** @var RateLimiterInterface $limiter */
        $limiter = $this->bfw->offsetGet($key);
        $dayKey = is_array($this->to) ? implode(',', $this->to) : $this->to;
        $limiter->handle(\BUtils::getClientIP(), $dayKey);
    }
}