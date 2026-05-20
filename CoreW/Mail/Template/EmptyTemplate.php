<?php


namespace CoreW\Mail\Template;


class EmptyTemplate implements EmailTemplateInterface
{
    public function parse($options)
    {
        return [
            'title' => empty($options['title']) ? '' : $options['title'],
            'body'  => empty($options['body']) ? '' : $options['body'],
        ];
    }
}