<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

interface CommandInterface
{
    /**
     * Get the command type name
     * 
     * @return string
     */
    public function getCommandType(): string;

    /**
     * Handle the incoming command
     * 
     * @param SimpleXMLElement $xml
     * @param string $deviceId
     * @param array $options
     * @return mixed
     */
    public function handle(SimpleXMLElement $xml, string $deviceId, array $options = []): mixed;

    /**
     * Generate response for the command
     * 
     * @param array $data
     * @param int $sn
     * @return string
     */
    public function generateResponse(array $data, int $sn): string;
}