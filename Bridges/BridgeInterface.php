<?php

namespace PHPPM\Bridges;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;

interface BridgeInterface
{
    /**
     * Bootstrap an application implementing the HttpKernelInterface.
     *
     * @param string|null $appBootstrap The environment your application will use to bootstrap (if any)
     * @param string $appenv
     * @param boolean $debug If debug is enabled
     * @param LoopInterface $loop
     * @see http://stackphp.com
     */
    public function bootstrap($appBootstrap, $appenv, $debug, LoopInterface $loop);

    /**
     * Handle a request using a HttpKernelInterface implementing application.
     *
     * @param RequestInterface $request
     * @return ResponseInterface
     */
    public function onRequest(RequestInterface $request);
}
