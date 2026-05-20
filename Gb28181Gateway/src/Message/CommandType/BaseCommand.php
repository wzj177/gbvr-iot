<?php

namespace Gb28181\GateWay\Message\CommandType;

use \SimpleXMLElement;

abstract class BaseCommand implements CommandInterface
{
    /**
     * Device ID
     *
     * @var string
     */
    protected string $deviceId;

    /**
     * Sequence number
     *
     * @var int
     */
    protected int $sn = 1;

    /**
     * Get next sequence number
     *
     * @return int
     */
    protected function getNextSn() : int
    {
        $this->sn++;
        if ($this->sn > 99999999) {
            $this->sn = 1;
        }
        return $this->sn;
    }

    /**
     * Generate XML response
     *
     * @param string $rootTag
     * @param array $data
     * @return string
     */
    protected function generateXml(string $rootTag, array $data) : string
    {
        $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"GB2312\"?><{$rootTag}></{$rootTag}>");

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Handle nested arrays
                $child = $xml->addChild($key);
                $this->arrayToXml($child, $value);
            } else {
                $xml->addChild($key, (string)$value);
            }
        }

        return $xml->asXML();
    }

    /**
     * Convert array to XML
     *
     * @param SimpleXMLElement $element
     * @param array $data
     * @return void
     */
    private function arrayToXml(SimpleXMLElement $element, array $data) : void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (is_numeric($key)) {
                    // For numeric keys, create siblings
                    $this->arrayToXml($element, $value);
                } else {
                    // For string keys, create child
                    $child = $element->addChild($key);
                    $this->arrayToXml($child, $value);
                }
            } else {
                if (is_numeric($key)) {
                    // For numeric keys, add as new child to parent
                    $element[0] = (string)$value;
                } else {
                    $element->addChild($key, (string)$value);
                }
            }
        }
    }
}