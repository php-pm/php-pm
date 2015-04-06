<?php

namespace PHPPM\Bridges;

use React\Http\Request as ReactRequest;
use React\Http\Response as ReactResponse;
use Zend\Http\PhpEnvironment\Request as ZendRequest;
use Zend\Http\PhpEnvironment\Response as ZendResponse;
use Zend\Http\Headers as ZendHeaders;
use Zend\Stdlib\Parameters;
use Zend\Mvc\MvcEvent;
use Zend\Mvc\SendResponseListener;
use Zend\Mvc\ResponseSender\SendResponseEvent;

class Zf2 implements BridgeInterface
{
    /**
     * @var \Zend\Mvc\Application
     */
    protected $application;

    /**
     * @param string $appBootstrap
     * @param string $appenv
     */
    public function bootstrap($appBootstrap, $appenv)
    {
        /* @var $bootstrap \PHPPM\Bootstraps\Zf2 */
        $bootstrap = new \PHPPM\Bootstraps\Zf2($appenv);
        $this->application = $bootstrap->getApplication();
    }

    /**
     * Handle a request using Zend\Mvc\Application.
     *
     * @param ReactRequest $request
     * @param ReactResponse $response
     */
    public function onRequest(ReactRequest $request, ReactResponse $response)
    {
        if (null === ($app = $this->application)) {
            return;
        }

        /* @var $sm \Zend\ServiceManager\ServiceManager */
        $sm = $app->getServiceManager();

        $zfRequest = new ZendRequest();
        $zfResponse = new ZendResponse();

        self::mapRequest($request, $zfRequest);

        $sm->setAllowOverride(true);
        $sm->setService('Request', $zfRequest);
        $sm->setService('Response', $zfResponse);
        $sm->setAllowOverride(false);

        $event = $app->getMvcEvent();
        $event->setRequest($zfRequest);
        $event->setResponse($zfResponse);

        try {
            $app->run($zfRequest, $zfResponse);
        } catch (\Exception $exception) {
            $response->writeHead(500); // internal server error
            $response->end();
            return;
        }

        self::mapResponse($response, $zfResponse);
    }

    /**
     * @param ReactRequest $reactRequest
     * @param ZendRequest $zfRequest
     */
    protected static function mapRequest(ReactRequest $reactRequest,
        ZendRequest $zfRequest)
    {
        $headers = new ZendHeaders();
        $headers->addHeaders($reactRequest->getHeaders());

        $query = new Parameters();
        $query->fromArray($reactRequest->getQuery());

        $zfRequest->setHeaders($headers);
        $zfRequest->setQuery($query);
        $zfRequest->setMethod($reactRequest->getMethod());
        $zfRequest->setUri($reactRequest->getPath());
        $zfRequest->setRequestUri($reactRequest->getPath());

        $server = $zfRequest->getServer();
        $server->set('REQUEST_URI', $reactRequest->getPath());
        $server->set('SERVER_NAME', $zfRequest->getHeader('Host'));
    }

    /**
     * @param ReactResponse $reactResponse
     * @param ZendResponse $zfResponse
     */
    protected static function mapResponse(ReactResponse $reactResponse,
        ZendResponse $zfResponse)
    {
        $headers = array_map('current', $zfResponse->getHeaders()->toArray());
        $reactResponse->writeHead($zfResponse->getStatusCode(), $headers);
        $reactResponse->end($zfResponse->getContent());
    }
}
