<?php

namespace PHPPM\Bridges;

use PHPPM\AppBootstrapInterface;
use PHPPM\Bootstraps\BootstrapInterface;
use PHPPM\Bridges\BridgeInterface;
use React\Http\Request as ReactRequest;
use React\Http\Response as ReactResponse;
use Stack\Builder;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class HttpKernel implements BridgeInterface
{
    /**
     * An application implementing the HttpKernelInterface
     *
     * @var \Symfony\Component\HttpFoundation\HttpKernelInterface
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
     * @param \React\Http\Request $request
     * @param \React\Http\Response $response
     */
    public function onRequest(ReactRequest $request, ReactResponse $response)
    {
        if (null === ($app = $this->application)) {
            return;
        }

        $content = '';
        $headers = $request->getHeaders();
        $contentLength = isset($headers['Content-Length']) ? (int) $headers['Content-Length'] : 0;

        $request->on('data', function($data)
            use ($request, $response, &$content, $contentLength)
        {
            // read data (may be empty for GET request)
            $content .= $data;

            // handle request after receive
            if (strlen($content) >= $contentLength) {
                $syRequest = self::mapRequest($request, $content);

                try {
                    $syResponse = $app->handle($syRequest);
                } catch (\Exception $exception) {
                    $response->writeHead(500); // internal server error
                    $response->end();
                    return;
                }
                
                self::mapResponse($response, $syResponse);
            }
        });
    }

    /**
     * Convert React\Http\Request to Symfony\Component\HttpFoundation\Request
     * 
     * @param ReactRequest $reactRequest
     * @return SymfonyRequest $syRequest
     */
    protected static function mapRequest(ReactRequest $reactRequest, $content)
    {
        $method = $reactRequest->getMethod();
        $headers = $reactRequest->getHeaders();
        $query = $reactRequest->getQuery();
        $post = array();

        // parse body?
        if (isset($headers['Content-Type']) && (0 === strpos($headers['Content-Type'], 'application/x-www-form-urlencoded')
            && in_array(strtoupper($method), array('PUT', 'DELETE', 'PATCH'))
        ) {
            parse_str($content, $post);
        }

        $syRequest = new SymfonyRequest(
            // $query, $request, $attributes, $cookies, $files, $server, $content
            $query, $post, array(), array(), array(), array(), $content
        );

        $syRequest->setMethod($method);
        $syRequest->headers->replace($headers);
        $syRequest->server->set('REQUEST_URI', $reactRequest->getPath());
        $syRequest->server->set('SERVER_NAME', explode(':', $headers['Host'])[0]);

        return $syRequest;
    }

    /**
     * Convert Symfony\Component\HttpFoundation\Response to React\Http\Response
     * 
     * @param ReactResponse $reactResponse
     * @param SymfonyResponse $syResponse
     */
    protected static function mapResponse(ReactResponse $reactResponse,
        SymfonyResponse $syResponse)
    {
        $headers = $syResponse->headers->all();
        $reactResponse->writeHead($syResponse->getStatusCode(), $headers);
        $reactResponse->end($syResponse->getContent());
    }
}
