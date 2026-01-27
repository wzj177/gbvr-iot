<?php

namespace CoreW\Business\Exception;

use CoreW\Exception\AbstractBizException;

class RecordFileException extends AbstractBizException 
{
    public function __construct($code, $message = null)
    {
        $this->setMessages();
        parent::__construct($code, $message);
    }

    /*
     * @return array|array[] 
     */
    public function setMessages()
    {
        $this->messages = [
        
        ];
    }

}
