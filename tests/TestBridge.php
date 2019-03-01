<?php

namespace PHPPM\Tests;

use RingCentral\Psr7;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use PHPPM\Bridges\StaticBridge;

class TestBridge extends StaticBridge
{
    /**
     * {@inheritdoc}
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        if(@$params['exit_prematurely'] == '1') {
            exit();
        }
        if(isset($params['sleep'])) {
            sleep($params['sleep']);
        }
        if(isset($params['memory'])) { // Allocate MB
            $longvar =  str_repeat('Lorem Ipsum', $params['memory']*1048576); // Create a multi-megabyte string
        }
        if(isset($params['exception'])) {
            register_shutdown_function(function() {
                file_put_contents('/tmp/ppmoutshutdownfunc', 'Shutdown function triggered');
            });
            throw new \Exception('This is a very bad exception');
        }
        return new Psr7\Response(404, ['Content-type' => 'text/plain'], 'Not found');
    }
}
