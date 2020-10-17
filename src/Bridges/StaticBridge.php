<?php

namespace PHPPM\Bridges;

use RingCentral\Psr7;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

class StaticBridge implements BridgeInterface
{
    /**
     * {@inheritdoc}
     */
    public function bootstrap($appBootstrap, $appenv, $debug)
    {
        // empty
    }

    /**
     * {@inheritdoc}
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Psr7\Response(404, ['Content-type' => 'text/plain'], 'Not found');
    }
}
