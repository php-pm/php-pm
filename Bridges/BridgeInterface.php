<?php

namespace PHPPM\Bridges;

use Aerys\Request;
use Aerys\Response;
use Amp\Promise;

interface BridgeInterface {
    /**
     * Bootstrap an application implementing the HttpKernelInterface.
     *
     * @param string|null $appBootstrap The environment your application will use to bootstrap (if any)
     * @param string      $appenv
     * @param boolean     $debug If debug is enabled
     *
     * @see http://stackphp.com
     */
    public function bootstrap($appBootstrap, $appenv, $debug);

    /**
     * Returns the repository which is used as root for the static file serving.
     *
     * @return string
     */
    public function getStaticDirectory(): string;

    /**
     * Handle a request using a HttpKernelInterface implementing application.
     *
     * @param Request  $request
     * @param Response $response
     *
     * @return Promise
     */
    public function onRequest(Request $request, Response $response): Promise;
}
