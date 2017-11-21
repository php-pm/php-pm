<?php

namespace PHPPM\Bridges;

use Psr\Http\Message\ServerRequestInterface;
use RingCentral\Psr7;

class InvokableMiddleware implements BridgeInterface
{
    use BootstrapTrait;

    /**
     * {@inheritdoc}
     */
    public function bootstrap($appBootstrap, $appenv, $debug)
    {
        $this->bootstrapApplicationEnvironment($appBootstrap, $appenv, $debug);

        if (!is_callable($this->middleware)) {
            throw new \Exception('Middleware must implement callable');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function handle(ServerRequestInterface $request)
    {
        $middleware = $this->middleware;
        return $middleware($request);
    }
}
