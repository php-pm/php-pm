<?php

namespace PHPPM\Bridges;

interface BridgeInterface
{
	/**
	 * Bootstrap an application implementing the HttpKernelInterface.
	 * 
	 * @param string $appBootstrap The name of the class used to bootstrap the application
	 * @param string|null $appBootstrap The environment your application will use to bootstrap (if any)
	 * @see http://stackphp.com
	 */
    public function bootstrap($appBootstrap, $appenv);


	/**
	 * Handle a request using a HttpKernelInterface implementing application.
	 *
	 * @param \React\Http\Request $request
	 * @param \React\Http\Response $response
	 */
    public function onRequest(\React\Http\Request $request, \React\Http\Response $response);
}
