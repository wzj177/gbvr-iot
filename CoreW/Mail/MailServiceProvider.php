<?php


namespace CoreW\Mail;


use CoreW\Mail\Template\EmailLoginSendEmailTemplate;
use CoreW\Mail\Template\EmailSystemSelfTestTemplate;
use CoreW\Mail\Template\EmailVIPVerifyEmailTemplate;
use CoreW\Mail\Template\EmptyTemplate;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class MailServiceProvider implements ServiceProviderInterface
{

    public function register(Container $app)
    {
        $this->registerEmailTemplate($app);
        $app["push_mail_default"] = function () {
            return function ($options, $debug) {
              return new DefaultMail($options, $debug);
            };
        };

        // TODO: 可以增加自家搭建的邮箱服务端并进行接口限流，默认是调用大厂的邮箱服务端
        $app["mail_factory"] = $app->factory(function ($bfw) {
            return function ($mailOptions) use ($bfw) {
                $mail = $bfw['push_mail_default']($mailOptions, config('app.debug'));
                $mail->setBfw($bfw);

                return $mail;
            };
        });
    }

    private function registerEmailTemplate(Container $app)
    {
        $app['email_template_paths'] = $app->factory(function () {
            return [];
        });
        $app['email_template_paths'] = $app->extend('email_template_paths', function ($paths, $app) {
            return array_merge($paths, [__DIR__.'/Template/twig']);
        });
        $app['email_template_parser'] = function ($app) {
            $parser = new EmailTemplateParser();
            $parser->setBfw($app);

            return $parser;
        };
        $app['empty_email_template'] = function ($app) {
            return new EmptyTemplate();
        };
        $app['email_system_self_test_template'] = function ($app) {
            $template = new EmailSystemSelfTestTemplate();
            $template->setBfw($app);

            return $template;
        };
        $app['email_vip_verify_email_template'] = function ($app) {
            $template = new EmailVIPVerifyEmailTemplate();
            $template->setBfw($app);

            return $template;
        };

        $app['email_login_send_email_code_template'] = function ($app) {
            $template = new EmailLoginSendEmailTemplate();
            $template->setBfw($app);

            return $template;
        };
    }
}