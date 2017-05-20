<?php

namespace PHPPM\Bridges;

use PHPPM\React\HttpResponse;
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
     * Returns the repository which is used as root for the static file serving.
     *
     * @return string
     */
    public function getStaticDirectory();

    /**
     * Handle a request using a HttpKernelInterface implementing application.
     *
     * @param \React\Http\Request $request
     * @param \PHPPM\React\HttpResponse $response
     */
    public function onRequest(\React\Http\Request $request, HttpResponse $response);
}
