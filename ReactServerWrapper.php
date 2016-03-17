<?php

namespace PHPPM;

use React\Http\Request;
use React\Socket\ConnectionInterface;

class ReactServerWrapper extends \React\Http\Server {

    public function handleRequest(ConnectionInterface $conn, Request $request, $bodyBuffer)
    {
        $response = new ReactResponseWrapper($conn);

        $response->on('close', array($request, 'close'));
        if (!$this->listeners('request')) {
            $response->end();
            return;
        }
        $this->emit('request', array($request, $response));

        $request->emit('data', array($bodyBuffer));
    }
}