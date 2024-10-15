<?php


namespace CoreW\Mail\Template;


class EmailSystemSelfTestTemplate extends BaseTemplate implements EmailTemplateInterface
{
    public function parse($options)
    {
        return [
            'title' => sprintf('【%s】系统自检邮件', $this->getSiteName()),
//            'body' => '系统邮件发送检测测试，请不要回复此邮件！',
            'body' => $this->renderBody('self-test.txt.twig', []),
            'format' => 'text/html'
        ];
    }
}