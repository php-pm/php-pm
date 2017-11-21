<?php

namespace PHPPM\Bridges;

use Interop\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use RingCentral\Psr7;

class Psr15Middleware implements BridgeInterface
{
    use BootstrapTrait;

    /**
     * {@inheritdoc}
     */
    public function bootstrap($appBootstrap, $appenv, $debug)
    {
        $this->bootstrapApplicationEnvironment($appBootstrap, $appenv, $debug);

        if (!$this->middleware instanceof RequestHandlerInterface) {
            throw new \Exception('Middleware must implement RequestHandlerInterface');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function handle(ServerRequestInterface $request)
    {
        $middleware = $this->middleware;
        return $middleware->handle($request);
    }
}
