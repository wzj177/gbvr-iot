<?php


namespace CoreW\Mail;


use CoreW\Context\BfwAware;

class EmailTemplateParser
{
    use BfwAware;

    public function parseTemplate($templateName, $arguments)
    {
        if (isset($this->bfw[$templateName . '_template'])) {
            return $this->bfw[$templateName . '_template']->parse($arguments);
        }

        return $this->bfw['empty_email_template']->parse($arguments);
    }
}