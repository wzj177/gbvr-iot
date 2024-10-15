<?php


namespace CoreW\Mail\Template;


interface EmailTemplateInterface
{
    /**
     * @param $options
     *
     * @return array
     */
    public function parse($options);
}