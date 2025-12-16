<?php

namespace Gb28181\GateWay\Transport;

class SipClient extends \ExoSipClient
{
    public function __construct(?array $config = null)
    {
        parent::__construct($config);
    }
}