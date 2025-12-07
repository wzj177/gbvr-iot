<?php

namespace CoreW\Business\Devices\Exception;

use CoreW\Exception\AbstractBizException;

class DeviceChannelException extends AbstractBizException 
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
