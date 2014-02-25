<?php

namespace PHPPM\Bridges;

use PHPPM\Bridges\BridgeInterface;

class Symfony implements BridgeInterface
{

    /**
     * @var \AppKernel
     */
    protected $kernel;

    public function bootstrap()
    {
        require_once './vendor/autoload.php';
        require_once './app/AppKernel.php';
        $this->kernel = new \AppKernel('prod', false);
        $this->kernel->loadClassCache();
    }

    public function onRequest(\React\Http\Request $request, \React\Http\Response $response)
    {
        $syRequest = new \Symfony\Component\HttpFoundation\Request();
        $syRequest->headers->replace($request->getHeaders());
        $syRequest->setMethod($request->getMethod());
        $syRequest->server->set('REQUEST_URI', $request->getPath());
        $syRequest->server->set('SERVER_NAME', explode(':', $request->getHeaders()['Host'])[0]);

        $syResponse = $this->kernel->handle($syRequest);
        $headers = array_map('current', $syResponse->headers->all());
        $response->writeHead($syResponse->getStatusCode(), $headers);
        $response->end($syResponse->getContent());
    }
}