<?php

namespace PHPPM\React;

use Evenement\EventEmitter;
use React\Http\Request;
use React\Http\ServerInterface;
use React\Socket\ServerInterface as SocketServerInterface;
use React\Socket\ConnectionInterface;

/**
 * This overwrite the default React\Http\Server
 * 
 * Modifications:
 *  * Different RequestParser
 *  * Different Response object
 * 
 * @see \React\Http\Server
 */
class HttpServer extends EventEmitter implements ServerInterface
{
    private $io;

    //copy&pasted from \React\Http\Server, load only RequestParser from different namespace.
    public function __construct(SocketServerInterface $io)
    {
        $this->io = $io;

        $this->io->on('connection', function (ConnectionInterface $conn) {
            // TODO: http 1.1 keep-alive
            // TODO: chunked transfer encoding (also for outgoing data)

            $parser = new RequestParser();
            $parser->on('headers', function (Request $request, $bodyBuffer) use ($conn, $parser) {
                // attach remote ip to the request as metadata
                $request->remoteAddress = $conn->getRemoteAddress();

                $this->handleRequest($conn, $request, $bodyBuffer);

                $conn->removeListener('data', array($parser, 'feed'));
                $conn->on('end', function () use ($request) {
                    $request->emit('end');
                });
                $conn->on('data', function ($data) use ($request) {
                    $request->emit('data', array($data));
                });
                $request->on('pause', function () use ($conn) {
                    $conn->emit('pause');
                });
                $request->on('resume', function () use ($conn) {
                    $conn->emit('resume');
                });
            });

            $conn->on('data', array($parser, 'feed'));

            $parser->on('expects_continue', function() use($conn) {
                $conn->write("HTTP/1.1 100 Continue\r\n\r\n");
            });
        });
    }

    public function handleRequest(ConnectionInterface $conn, Request $request, $bodyBuffer)
    {
        $response = new HttpResponse($conn);

        $response->on('close', array($request, 'close'));
        if (!$this->listeners('request')) {
            $response->end();
            return;
        }
        $this->emit('request', array($request, $response));

        $request->emit('data', array($bodyBuffer));
    }
}