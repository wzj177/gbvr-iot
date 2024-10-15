<?php


namespace CoreW\Mail\Template;


class EmailVIPVerifyEmailTemplate extends BaseTemplate implements EmailTemplateInterface
{

    public function parse($options)
    {
        return [
            'title' => $this->getSiteName(),
            'body' => $this->renderBody('email-verify.txt.twig', $options['params'] ?? []),
            'format' => 'text/html'
        ];
    }
}