<?php

namespace PHPPM\React;

class HttpResponse extends \React\Http\Response {

    protected $statusCode = 0;
    protected $headers = [];

    protected $bytesSent = 0;

    public function writeHead($status = 200, array $headers = array())
    {
        parent::writeHead($status, $headers);
        $this->statusCode = $status;
    }

    public function write($data) {
        $this->bytesSent += strlen($data);
        parent::write($data);
    }

    /**
     * @return int
     */
    public function getBytesSent()
    {
        return $this->bytesSent;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @param array $headers
     */
    public function setHeaders($headers)
    {
        $this->headers = $headers;
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @param int $statusCode
     */
    public function setStatusCode($statusCode)
    {
        $this->statusCode = $statusCode;
    }
}