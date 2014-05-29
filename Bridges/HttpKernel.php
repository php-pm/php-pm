<?php

namespace PHPPM\Bridges;

use PHPPM\AppBootstrapInterface;
use PHPPM\Bootstraps\BootstrapInterface;
use PHPPM\Bridges\BridgeInterface;
use React\Http\Request as ReactRequest;
use React\Http\Response as ReactResponse;
use Stack\Builder;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

class HttpKernel implements BridgeInterface
{
    /**
     * An application implementing the HttpKernelInterface
     *
     * @var Symfony\Component\HttpFoundation\HttpKernelInterface
     */
    protected $application;

    /**
     * Bootstrap an application implementing the HttpKernelInterface.
     *
     * In the process of bootstrapping we decorate our application with any number of
     * *middlewares* using StackPHP's Stack\Builder.
     *
     * The app bootstraping itself is actually proxied off to an object implementing the
     * PHPPM\Bridges\BridgeInterface interface which should live within your app itself and
     * be able to be autoloaded.
     *
     * @param string $appBootstrap The name of the class used to bootstrap the application
     * @param string|null $appBootstrap The environment your application will use to bootstrap (if any)
     * @see http://stackphp.com
     */
    public function bootstrap($appBootstrap, $appenv)
    {
        require_once './vendor/autoload.php';

        if (false === class_exists($appBootstrap)) {
            $appBootstrap = '\\' . $appBootstrap;
            if (false === class_exists($appBootstrap)) {
                return false;
            }
        }

        $bootstrap = new $appBootstrap($appenv);

        if ($bootstrap instanceof BootstrapInterface) {
            $app = $bootstrap->getApplication();

            $stack = new Builder();
            $stack = $bootstrap->getStack($stack);
            $this->application = $stack->resolve($app);
        }
    }

    /**
     * Handle a request using a HttpKernelInterface implementing application.
     *
     * @param React\Http\Request $request
     * @param React\Http\Response $response
     */
    public function onRequest(ReactRequest $request, ReactResponse $response)
    {
        if (null !== $this->application) {
            try {
                $syRequest = new SymfonyRequest();
                $syRequest->headers->replace($request->getHeaders());
                $syRequest->setMethod($request->getMethod());
                $syRequest->server->set('REQUEST_URI', $request->getPath());
                $syRequest->server->set('SERVER_NAME', explode(':', $request->getHeaders()['Host'])[0]);

                $syResponse = $this->application->handle($syRequest);
                $this->application->terminate($syRequest, $syResponse);

                $headers = array_map('current', $syResponse->headers->all());
                $response->writeHead($syResponse->getStatusCode(), $headers);
                $response->end($syResponse->getContent());
            } catch (\Exception $e) {
                //
            }
        }
    }
}
