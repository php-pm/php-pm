<?php

namespace PHPPM\React;

use React\Http\MultipartParser;
use React\Http\Request;

class RequestParser extends \React\Http\RequestParser
{
    public function parseHeaders($data)
    {
        $request = parent::parseHeaders($data);
        $this->fixHeaderNames($request);
        return $request;
    }

    //fix header names (Content-type => Content-Type)
    protected function fixHeaderNames(Request $request)
    {
        $headers = $request->getHeaders();
        foreach ($headers as $name => $v) {
            $newName = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', $name))));
            $headers[$newName] = $headers[$name];
        }

        if (isset($headers['Content-Type'])) {
            $headers['Content-Type'] = explode(';', $headers['Content-Type'])[0];
        }

        $request->__construct($request->getMethod(), $request->getUrl(), $request->getQuery(), $request->getHttpVersion(), $headers, $request->getBody());
    }
}