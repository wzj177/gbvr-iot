<?php


namespace CoreW\Mail\Template;


class EmailLoginSendEmailTemplate extends BaseTemplate implements EmailTemplateInterface
{

    public function parse($options)
    {
        return [
            'title'  => $this->getSiteName(),
            'body'   => $this->renderBody('login-email-code.txt.twig', $options['params'] ?? []),
            'format' => 'text/html',
        ];
    }
}