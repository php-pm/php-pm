<?php

namespace PHPPM\React;

use React\Http\Request;

class RequestParser extends \React\Http\RequestHeaderParser
{
    public function parseRequest($data)
    {
        $return = parent::parseRequest($data);
        $this->fixHeaderNames($return[0]);
        return $return;
    }

    //fix header names (Content-type => Content-Type)
    protected function fixHeaderNames(Request $request)
    {
        $headers = $request->getHeaders();
        foreach ($headers as $name => $value) {
            $newName = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', $name))));
            unset($headers[$name]);
            $headers[$newName] = $value;
        }

        if (isset($headers['Content-Type'])) {
            $headers['Content-Type'] = explode(';', $headers['Content-Type'])[0];
        }

        $request->__construct($request->getMethod(), $request->getPath(), $request->getQuery(), $request->getHttpVersion(), $headers);
    }
}
