<?php

namespace CoreW\Sdk\Iot;

class HttpResponse
{
    /**
     * @var array the response headers in the form of an associative array
     */
    protected array $headers;

    /**
     * @var string the raw response body
     */
    protected string $body;

    /**
     * @var int the HTTP status response code
     */
    protected int $httpResponseCode;


    /**
     * Creates a new Response instance.
     *
     * @param string|array $headers the headers as a raw string or array
     * @param string $body the raw response body
     * @param int|null $httpStatusCode the HTTP response code (if sending headers as parsed array)
     */
    public function __construct($headers, string $body, ?int $httpStatusCode = null)
    {
        if (is_numeric($httpStatusCode)) {
            $this->httpResponseCode = (int)$httpStatusCode;
        }

        if (is_array($headers)) {
            $this->headers = $headers;
        } else {
            $this->setHeadersFromString($headers);
        }

        $this->body = $body;
    }

    /**
     * Return the response headers.
     *
     * @return array
     */
    public function getHeaders() : array
    {
        return $this->headers;
    }

    /**
     * Return the body of the response.
     *
     * @return string
     */
    public function getBody() : string
    {
        return $this->body;
    }

    /**
     * Return the HTTP response code.
     *
     * @return int
     */
    public function getHttpResponseCode() : int
    {
        return $this->httpResponseCode;
    }

    /**
     * Sets the HTTP response code from a raw header.
     *
     * @param string $rawResponseHeader
     */
    public function setHttpResponseCodeFromHeader(string $rawResponseHeader)
    {
        //        preg_match('|HTTP/\d\.\d\s+(\d+)\s+.*|', $rawResponseHeader, $match);
        //        $this->httpResponseCode = (int)$match[1];
        preg_match('|HTTP/(\d+\.?\d*)\s+(\d+)|', $rawResponseHeader, $match);
        $this->httpResponseCode = (int)$match[2];

    }

    /**
     * Parse the raw headers and set as an array.
     *
     * @param string $rawHeaders the raw headers from the response
     */
    protected function setHeadersFromString(string $rawHeaders)
    {
        // Normalize line breaks
        $rawHeaders = str_replace("\r\n", "\n", $rawHeaders);

        // There will be multiple headers if a 301 was followed
        // or a proxy was followed, etc
        $headerCollection = explode("\n\n", trim($rawHeaders));
        // We just want the last response (at the end)
        $rawHeader = array_pop($headerCollection);

        $headerComponents = explode("\n", $rawHeader);
        foreach ($headerComponents as $line) {
            if (false === strpos($line, ': ')) {
                $this->setHttpResponseCodeFromHeader($line);
            } else {
                [$key, $value] = explode(': ', $line, 2);
                $this->headers[$key] = $value;
            }
        }
    }

    public function __toString()
    {
        $headers = print_r($this->headers, true);

        return sprintf("#HTTP RESPONSE# ==Status Code==: %s\t==Headers==: %s\t==Body==: %s", $this->httpResponseCode, $headers, $this->body);
    }
}